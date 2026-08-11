<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan drama dari pengguna.
 *
 * ## Judul disimpan apa adanya
 *
 * Yang diketik pengguna adalah judul versi dia — bisa judul Inggris, judul
 * Korea, terjemahan bebas, atau salah eja. Tidak ada gunanya memaksanya
 * cocok dengan katalog: kalau judulnya sudah ada di katalog, ia tidak akan
 * memintanya.
 *
 * ## Kenapa drama_id nullable dan terpisah dari judul
 *
 * Saat admin menyediakan dramanya, permintaan dihubungkan ke baris drama yang
 * sebenarnya. Judul permintaan TIDAK ditimpa — pengguna perlu mengenali
 * permintaan miliknya di daftar, dan ia mengingatnya dengan kata-kata yang ia
 * ketik sendiri, bukan dengan judul resmi yang mungkin sangat berbeda.
 *
 * ## notified_at, bukan boolean
 *
 * Menyimpan waktunya sekaligus menjawab "sudah diberi tahu atau belum" dan
 * "kapan" — dan yang kedua adalah pertanyaan pertama yang muncul ketika ada
 * yang bilang tidak menerima pemberitahuan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drama_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                // Permintaan ikut terhapus bersama akunnya. Berbeda dari
                // tagihan yang harus tersimpan sebagai catatan keuangan,
                // permintaan drama tidak punya nilai apa pun tanpa orang yang
                // memintanya — tidak ada yang bisa diberi tahu lagi.
                ->cascadeOnDelete();

            $table->string('title', 200);

            // Tahun dan catatan membantu admin membedakan judul yang sama
            // milik beberapa drama berbeda. Keduanya opsional; form yang
            // panjang membuat orang urung mengisi.
            $table->string('year', 10)->nullable();
            $table->text('note')->nullable();

            $table->string('status', 20)->default('pending');

            $table->text('admin_note')->nullable();

            $table->foreignId('drama_id')->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->timestamp('notified_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);

            // Dipakai mendeteksi permintaan kembar. Bukan unique: dua orang
            // boleh meminta drama yang sama, dan justru itu sinyal berharga
            // tentang apa yang paling dicari.
            $table->index('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drama_requests');
    }
};
