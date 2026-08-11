<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat postingan katalog ke channel Telegram.
 *
 * ## Kenapa perlu dicatat
 *
 * Dua alasan, dan yang kedua yang membuatnya wajib.
 *
 * Pertama, admin perlu tahu drama mana yang sudah diposting sampai episode
 * berapa. Tanpa catatan, satu-satunya cara mengetahuinya adalah menggulir
 * channel — dan itu berarti drama yang sama diposting dua kali oleh orang
 * yang lupa.
 *
 * Kedua, kiriman otomatis saat publikasi TIDAK BOLEH mengirim dua kali.
 * Sebuah drama bisa dipublikasikan, ditarik untuk perbaikan, lalu
 * dipublikasikan lagi — tiga kali menyimpan berarti tiga postingan identik di
 * channel kalau tidak ada yang mengingat bahwa yang pertama sudah terkirim.
 *
 * ## Kenapa message_id ikut disimpan
 *
 * Supaya postingan bisa dihapus atau disunting dari panel nanti. Tanpa id
 * pesannya, satu-satunya cara memperbaiki postingan yang salah adalah membuka
 * Telegram dan mencarinya sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_posts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('drama_id')
                ->constrained()
                // restrict, bukan cascade: riwayat kiriman adalah catatan apa
                // yang pernah dikirim ke publik, dan itu tetap benar meski
                // dramanya kemudian dihapus dari katalog.
                ->restrictOnDelete();

            // Rentang episode yang ikut dalam kiriman ini. Disimpan sebagai
            // nomor episode, bukan id — yang dibaca admin di panel dan yang
            // tertulis di caption adalah nomornya.
            $table->unsignedInteger('from_episode')->nullable();
            $table->unsignedInteger('to_episode')->nullable();

            // Chat tujuan disalin, tidak dibaca ulang dari pengaturan.
            // Pengaturan bisa berubah; catatan ini harus tetap menunjukkan ke
            // mana pesannya benar-benar dikirim.
            $table->string('chat_id', 64);

            // Satu kiriman bisa jadi beberapa pesan bila episodenya banyak.
            $table->json('message_ids')->nullable();

            $table->unsignedSmallInteger('episode_count')->default(0);

            // 'manual' atau 'auto'. Dipakai memilah di panel: kiriman otomatis
            // yang gagal butuh perhatian berbeda dari yang ditekan orang.
            $table->string('source', 20)->default('manual');

            $table->string('status', 20)->default('sent');

            $table->text('error')->nullable();

            $table->foreignId('sent_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['drama_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_posts');
    }
};
