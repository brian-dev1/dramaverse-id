<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ganti label menu "Buka Website" jadi "Buka Aplikasi".
 *
 * ## Kenapa perlu migrasi, bukan cukup mengubah DEFAULTS
 *
 * `TelegramMenuService::DEFAULTS` hanya dipakai saat menu dibuat pertama
 * kali. Pemasangan yang sudah berjalan punya barisnya sendiri di tabel
 * `telegram_menus`, dan baris itu tidak pernah dibaca ulang dari DEFAULTS —
 * memang begitu maksudnya, karena admin boleh menamainya sesuka hati dari
 * panel.
 *
 * Akibatnya, mengubah DEFAULTS saja membuat pemasangan baru menyebut
 * "Aplikasi" sementara yang lama tetap menyebut "Website" selamanya.
 *
 * ## Kenapa hanya yang masih bawaan yang diubah
 *
 * Kalau admin sudah menamainya sendiri — "Nonton di Web", "Buka Situs", apa
 * pun — itu keputusan yang lebih tahu konteks daripada migrasi ini.
 * Menimpanya berarti mengembalikan pekerjaan orang tanpa diminta.
 *
 * Jadi yang disentuh hanya baris yang labelnya masih persis "Buka Website".
 * Pola yang sama dipakai `refresh_channel_caption_template`.
 */
return new class extends Migration
{
    private const LAMA = 'Buka Website';

    private const BARU = 'Buka Aplikasi';

    public function up(): void
    {
        if (! Schema::hasTable('telegram_menus')) {
            return;
        }

        DB::table('telegram_menus')
            ->where('label', self::LAMA)
            ->update(['label' => self::BARU]);
    }

    /**
     * Dikembalikan hanya bila labelnya masih persis hasil migrasi ini.
     *
     * Admin yang menamainya ulang setelah migrasi berjalan tidak kehilangan
     * namanya saat seseorang melakukan rollback.
     */
    public function down(): void
    {
        if (! Schema::hasTable('telegram_menus')) {
            return;
        }

        DB::table('telegram_menus')
            ->where('label', self::BARU)
            ->update(['label' => self::LAMA]);
    }
};
