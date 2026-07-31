<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran bertahap.
 *
 * Trakteer bekerja dengan satuan — pendukung mengirim sejumlah "cendol", dan
 * satu paket berharga beberapa kali harga satuannya. Pengguna bisa mengirim
 * lima cendol sekarang dan lima lagi nanti, dan keduanya datang sebagai
 * webhook terpisah.
 *
 * Tanpa kolom ini, callback kedua akan dianggap kurang bayar dan ditolak —
 * padahal jumlahnya sudah cukup. `paid_amount` menjumlahkannya.
 *
 * Nilainya menumpuk, tidak pernah dikurangi. Pengembalian dana dicatat di
 * `refund_status` transaksi, bukan dengan mengurangi angka ini — kalau tidak,
 * riwayat "berapa yang pernah masuk" hilang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {

            $table->decimal('paid_amount', 12, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
};
