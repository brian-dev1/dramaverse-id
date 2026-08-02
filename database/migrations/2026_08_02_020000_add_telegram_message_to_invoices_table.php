<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak pesan tagihan di Telegram.
 *
 * Bot mengirim satu pesan berisi nomor tagihan dan tombol bayar setiap kali
 * tagihan dibuat. Saat tagihan itu dibatalkan otomatis karena basi (lihat
 * `InvoiceService::expireStale()`), pesannya perlu dihapus juga — kalau
 * tidak, pengguna masih melihat tombol "Bayar sekarang" untuk tagihan yang
 * sudah tidak berlaku.
 *
 * Disimpan di invoice, bukan di transaksi: satu invoice hanya pernah punya
 * satu pesan bot yang relevan (percobaan ulang memakai transaksi baru tapi
 * tidak mengirim pesan baru).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {

            $table->bigInteger('telegram_chat_id')->nullable()->after('note');

            $table->bigInteger('telegram_message_id')->nullable()->after('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'telegram_message_id']);
        });
    }
};