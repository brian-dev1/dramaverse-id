<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rate komisi khusus per pengguna.
 *
 * Tingkatan komisi otomatis (`referral_tiers`) tetap menjadi aturan bagi
 * semua orang. Kolom ini adalah pengecualian yang disengaja untuk segelintir
 * orang — mitra dekat, kerja sama tertentu — yang rate-nya ditetapkan sendiri
 * dan tidak boleh ikut berubah saat jumlah undangannya naik atau saat tabel
 * tingkatan disunting.
 *
 * ## Kenapa kolom di `users`, bukan tabel sendiri
 *
 * Yang disimpan hanya satu angka yang berlaku sekarang, satu baris per orang,
 * dan selalu dibaca bersama barisnya pengguna saat komisi dihitung. Tabel
 * terpisah hanya menambah satu join pada jalur yang dipanggil setiap
 * pembayaran, tanpa menyimpan apa pun yang tidak muat di sini.
 *
 * Riwayat perubahannya tidak hilang: `rate` disalin ke setiap baris komisi
 * saat komisi dibuat, jadi berapa persen yang berlaku pada satu transaksi
 * tetap terbaca di `referral_commissions` meski rate-nya diubah belakangan.
 *
 * ## NULL berarti "ikut tingkatan otomatis"
 *
 * Bukan 0. Nol adalah rate yang sah dan berarti sesuatu yang lain: orang ini
 * tidak dapat komisi sama sekali, sekalipun tingkatannya bilang dapat. Karena
 * itu kolomnya nullable dan pembeda keduanya adalah ada-tidaknya isi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'referral_rate_override')) {
                // 5,2 — sama persis dengan `referral_tiers.rate`, supaya tidak
                // ada nilai yang sah di satu tempat tetapi terpotong di
                // tempat lain. Maksimum 100.00 dijaga validasi.
                $table->decimal('referral_rate_override', 5, 2)
                    ->nullable()
                    ->after('referred_at');
            }

            if (! Schema::hasColumn('users', 'referral_rate_note')) {
                // Alasannya ditulis di sebelah angkanya. Enam bulan dari
                // sekarang, "50%" tanpa keterangan adalah angka yang tidak
                // berani diubah siapa pun karena tidak ada yang ingat kenapa.
                $table->string('referral_rate_note', 255)
                    ->nullable()
                    ->after('referral_rate_override');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['referral_rate_override', 'referral_rate_note'] as $kolom) {
                if (Schema::hasColumn('users', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
