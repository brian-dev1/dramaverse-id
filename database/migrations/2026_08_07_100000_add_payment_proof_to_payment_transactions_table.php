<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bukti bayar yang dikirim pengguna lewat bot.
 *
 * ## Kenapa di transaksi, bukan di invoice
 *
 * Satu invoice bisa punya beberapa percobaan pembayaran — pengguna yang gagal
 * dengan satu metode lalu mencoba metode lain menghasilkan baris kedua. Bukti
 * transfer itu milik PERCOBAAN tertentu, bukan milik tagihannya. Menempelkan
 * ke invoice berarti bukti percobaan kedua menimpa bukti percobaan pertama,
 * dan admin yang menelusuri sengketa kehilangan separuh riwayatnya.
 *
 * ## Dua salinan, dengan sengaja
 *
 * `proof_path` adalah berkas yang KITA simpan di disk `public`; itu yang
 * dibuka admin di panel. `proof_file_id` adalah id berkas milik Telegram.
 *
 * Keduanya disimpan karena masing-masing bisa hilang sendiri-sendiri: disk
 * kita bisa dibersihkan, dan file_id Telegram bisa kedaluwarsa kalau botnya
 * diganti. Menyimpan yang kedua tidak memakan tempat — ia cuma teks — dan
 * membuat bukti masih bisa ditarik ulang saat yang pertama raib.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {

            $table->string('proof_path', 500)->nullable()->after('signature');

            $table->string('proof_file_id', 191)->nullable()->after('proof_path');

            $table->timestamp('proof_uploaded_at')->nullable()->after('proof_file_id');

            // Catatan yang diketik pengguna bersama buktinya (caption foto).
            $table->string('proof_note', 500)->nullable()->after('proof_uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'proof_path',
                'proof_file_id',
                'proof_uploaded_at',
                'proof_note',
            ]);
        });
    }
};
