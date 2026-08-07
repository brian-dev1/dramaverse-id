<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan setiap video yang bot kirimkan ke chat pengguna.
 *
 * ## Kenapa harus dicatat
 *
 * Telegram tidak menyediakan cara bertanya "pesan apa saja yang pernah saya
 * kirim ke chat ini". Satu-satunya cara menghapus sebuah pesan adalah
 * menyebut `chat_id` DAN `message_id`-nya. Kalau pasangan itu tidak pernah
 * disimpan pada saat pengiriman, pesannya tidak akan pernah bisa dihapus —
 * tidak ada jalan memulihkannya belakangan.
 *
 * ## Batas 48 jam
 *
 * `deleteMessage` hanya berlaku untuk pesan yang usianya kurang dari 48 jam.
 * Itu aturan Telegram, bukan keterbatasan yang bisa diakali. Karena itu tabel
 * ini menyimpan status per baris: yang lewat batas ditandai `too_old` supaya
 * admin melihat kenyataannya, bukan kegagalan yang diam-diam diulang terus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_deliveries', function (Blueprint $table) {

            $table->id();

            // Nullable: pengguna bisa dihapus, catatan pesannya tetap perlu
            // ada agar pembersihan tetap bisa dijalankan.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('episode_id')->nullable()->constrained()->nullOnDelete();

            // bigint: chat_id Telegram melewati batas 32-bit untuk supergroup.
            $table->bigInteger('chat_id');

            $table->bigInteger('message_id');

            // Hanya yang premium yang perlu ditarik kembali saat VIP habis.
            // Menyimpan penanda ini di sini, bukan menanyakannya ulang ke
            // tabel episode saat pembersihan, karena status premium sebuah
            // episode bisa berubah setelah video terkirim.
            $table->boolean('is_premium')->default(false);

            $table->timestamp('sent_at');

            // Diisi bila video memang dijadwalkan hilang sendiri.
            $table->timestamp('delete_after')->nullable();

            // pending | deleted | too_old | failed | skipped
            $table->string('delete_status', 20)->default('pending');

            $table->timestamp('deleted_at')->nullable();

            $table->string('delete_error')->nullable();

            $table->timestamps();

            // Jalur baca utama pembersihan: "premium milik user X yang belum
            // dihapus".
            $table->index(['user_id', 'is_premium', 'delete_status']);

            // Jalur baca penghapusan terjadwal.
            $table->index(['delete_status', 'delete_after']);

            $table->index('sent_at');

            // Satu pesan hanya boleh punya satu baris. Tanpa ini, pengiriman
            // yang di-retry membuat dua catatan dan penghapusan kedua selalu
            // gagal dengan "message to delete not found".
            $table->unique(['chat_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_deliveries');
    }
};
