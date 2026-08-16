<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengumuman bebas ke channel Telegram.
 *
 * ## Kenapa tabel sendiri, bukan menumpang channel_posts
 *
 * `channel_posts` mencatat pengiriman KATALOG: ia selalu punya drama_id,
 * rentang episode, dan jumlah part. Pengumuman tidak punya satu pun dari
 * itu — isinya tulisan bebas. Menumpangkannya di sana berarti setengah kolom
 * selalu kosong, `drama_id` yang sekarang wajib harus dilonggarkan, dan
 * penjagaan "drama ini sudah pernah dikirim" jadi harus ikut memeriksa
 * baris yang bukan drama.
 *
 * ## Kenapa isinya disimpan, bukan langsung dikirim lalu dilupakan
 *
 * Tiga alasan. Pengumuman terjadwal jelas harus tersimpan sampai waktunya
 * tiba. Pengumuman yang gagal perlu bisa dilihat sebabnya dan dikirim ulang
 * tanpa mengetik ulang. Dan yang ketiga: pengumuman adalah tulisan yang
 * dibaca semua pelanggan channel — kalau ada yang salah, orang pertama yang
 * ditanya adalah admin, dan ia perlu bisa membuka lagi apa yang persisnya
 * terkirim, bukan mengingat-ingat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_announcements', function (Blueprint $table) {
            $table->id();

            // Isi pengumuman, HTML sederhana seperti caption katalog.
            // `text`, bukan string: batas Telegram 4096 karakter dan satu
            // karakter bisa memakan empat byte.
            $table->text('body');

            // Path relatif di disk `public`. Kosong berarti pengumuman
            // dikirim sebagai pesan teks biasa, bukan foto bercaption —
            // beda batas panjangnya, 4096 lawan 1024.
            $table->string('image')->nullable();

            /*
            | Tombol tautan di bawah pengumuman: [{"label":"...","url":"..."}]
            |
            | Disimpan sebagai json, bukan tabel terpisah. Tombol tidak punya
            | umur sendiri di luar pengumumannya, tidak pernah dicari, dan
            | tidak pernah diubah satuan — tabel anak untuk data seperti itu
            | cuma menambah satu join tanpa satu pun pertanyaan yang bisa
            | dijawabnya.
            */
            $table->json('buttons')->nullable();

            // Null berarti "kirim sekarang". Terisi berarti menunggu giliran;
            // command `channel:announce-due` yang memungutnya.
            $table->timestamp('scheduled_at')->nullable();

            // scheduled | sent | failed | cancelled
            $table->string('status', 16)->default('scheduled');

            // Chat tujuan disalin saat dikirim, tidak dibaca ulang dari
            // pengaturan. Pengaturan bisa berubah; catatan ini harus tetap
            // menunjukkan ke mana pesannya benar-benar dikirim.
            $table->string('chat_id', 64)->nullable();

            $table->unsignedBigInteger('message_id')->nullable();

            $table->text('error')->nullable();

            $table->timestamp('sent_at')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                // Riwayat pengumuman adalah catatan apa yang pernah dibaca
                // publik. Ia tetap benar meski admin yang menulisnya sudah
                // dihapus, jadi kolomnya dikosongkan, bukan barisnya ikut
                // terhapus.
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Satu-satunya pertanyaan yang ditanyakan penjadwal setiap menit:
            // "mana yang statusnya scheduled dan waktunya sudah lewat".
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_announcements');
    }
};
