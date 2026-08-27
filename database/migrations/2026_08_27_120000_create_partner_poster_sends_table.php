<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan kiriman poster ke grup partner.
 *
 * ## Kenapa tabel sendiri, bukan menumpang `channel_posts`
 *
 * Keduanya sama-sama "mengirim drama ke Telegram", tetapi tidak lebih dari
 * itu. Kiriman channel berisi poster DAN daftar episode berikut tautan bot,
 * ditujukan ke pelanggan, dan punya rentang part. Kiriman partner cuma poster
 * dan judul, ditujukan ke segelintir orang yang akan mempostingnya ulang di
 * media sosial mereka sendiri, dan tidak mengenal rentang sama sekali.
 *
 * Menyatukannya berarti satu tabel dengan separuh kolom selalu null,
 * dan — yang lebih merepotkan — "sudah pernah dikirim" jadi ambigu: dikirim
 * ke channel, ke grup partner, atau keduanya? Padahal justru pertanyaan itu
 * yang menentukan drama mana yang muncul saat tombol Kirim Semua ditekan.
 *
 * ## Kenapa tidak ada unique(drama_id, chat_id)
 *
 * Kegagalan juga dicatat. Batasan unik akan membuat percobaan kedua atas
 * drama yang gagal ditolak database, bukan dikirim ulang. Keunikan yang
 * sebenarnya diinginkan — "belum pernah BERHASIL dikirim ke grup ini" —
 * bergantung pada status, dan itu urusan query, bukan indeks.
 *
 * Pola ini sama dengan `channel_posts`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_poster_sends', function (Blueprint $table) {

            $table->id();

            $table->foreignId('drama_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
            | Chat id ikut disimpan, bukan hanya dibaca dari pengaturan saat
            | menampilkan. Kalau grup partner suatu saat diganti, riwayat lama
            | harus tetap menunjuk grup yang dulu benar-benar menerimanya —
            | dan drama yang sudah dikirim ke grup lama otomatis kembali
            | muncul sebagai "belum dikirim" untuk grup baru, yang memang
            | perilaku yang diinginkan.
            */
            $table->string('chat_id', 64);

            $table->unsignedBigInteger('thread_id')->nullable();

            $table->unsignedBigInteger('message_id')->nullable();

            $table->string('status', 16)->default('queued');

            $table->text('error')->nullable();

            $table->foreignId('sent_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // Pertanyaan yang paling sering ditanyakan ke tabel ini:
            // "drama mana saja yang sudah berhasil masuk ke grup ini".
            $table->index(['chat_id', 'status']);
            $table->index(['drama_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_poster_sends');
    }
};
