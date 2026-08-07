<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda bahwa pemberitahuan "paket sudah berakhir" sudah dikirim.
 *
 * Tanpa kolom ini, scheduler yang berjalan tiap sepuluh menit akan mengirim
 * pesan yang sama berulang-ulang ke pengguna yang langganannya baru habis —
 * dan pengguna yang di-spam bot cenderung memblokir botnya, bukan
 * memperpanjang.
 *
 * Kolomnya ada di `subscriptions`, bukan di `users`, karena satu pengguna bisa
 * punya beberapa langganan sepanjang waktu dan masing-masing berhak atas satu
 * pemberitahuan berakhir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {

            $table->timestamp('expiry_notified_at')->nullable()->after('cancelled_at');

            // Dibaca scheduler: "sudah lewat, berstatus expired, belum
            // diberitahu". Tanpa index, query itu memindai seluruh tabel
            // setiap sepuluh menit selamanya.
            $table->index(['status', 'expiry_notified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['status', 'expiry_notified_at']);
            $table->dropColumn('expiry_notified_at');
        });
    }
};
