<?php

use App\Services\Admin\SettingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Perluas template caption channel: tambah tautan cari, VIP, dan request.
 *
 * ## Kenapa bawaan lama ditulis ulang sebagai literal di sini
 *
 * Migrasi sebelumnya membandingkan nilai tersimpan dengan
 * `SettingService::TEMPLATE_BAWAAN`. Begitu konstanta itu diubah — dan itu
 * yang dilakukan perubahan ini — pembandingnya ikut berubah, sehingga migrasi
 * lama berhenti mengenali template yang dulu ia pasang sendiri.
 *
 * Karena itu bentuk lamanya dibekukan di sini sebagai teks apa adanya. Migrasi
 * adalah catatan sejarah: ia harus tetap benar walau kode di sekitarnya
 * bergerak.
 *
 * ## Yang disunting admin tidak disentuh
 *
 * Sama seperti sebelumnya, hanya baris yang isinya PERSIS sama dengan bawaan
 * lama yang diperbarui. Template adalah tulisan milik admin, dan menimpanya
 * diam-diam menghapus susunan yang mungkin sudah ia rapikan sendiri.
 */
return new class extends Migration
{
    /** Bawaan yang dipasang migrasi 2026_08_12_090000. */
    private const TEMPLATE_LAMA = <<<'CAPTION'
        🎬 <b>{judul}</b>
        {negara} • {total_episode} Episode • {genre}

        <blockquote>{sinopsis}</blockquote>

        ━━━━━━━━━━━━━━━
        {daftar}
        ━━━━━━━━━━━━━━━

        🆓 Gratis   💎 Khusus VIP
        📺 <a href="{tautan_drama}">Lihat semua episode</a>
        ⭐ <a href="{tautan_vip}">Buka akses VIP</a>
        CAPTION;

    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'channel_template')
            ->where('value', self::TEMPLATE_LAMA)
            ->update(['value' => SettingService::TEMPLATE_BAWAAN]);

        app(SettingService::class)->flush();
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'channel_template')
            ->where('value', SettingService::TEMPLATE_BAWAAN)
            ->update(['value' => self::TEMPLATE_LAMA]);

        app(SettingService::class)->flush();
    }
};
