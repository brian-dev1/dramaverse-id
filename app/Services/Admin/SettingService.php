<?php

namespace App\Services\Admin;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Pembaca dan penulis pengaturan situs.
 *
 * Seluruh isi tabel dimuat sekali lalu di-cache, jadi memanggil setting()
 * berkali-kali dalam satu permintaan tidak menambah query.
 */
class SettingService
{
    private const CACHE_KEY = 'settings:all';

    /**
     * Caption bawaan untuk postingan channel.
     *
     * Disimpan sebagai konstanta, bukan ditulis langsung di SCHEMA, karena
     * migrasi `refresh_channel_template` perlu membandingkannya dengan nilai
     * yang sudah tersimpan — untuk memperbarui pemasangan yang templatenya
     * masih bawaan tanpa menimpa yang sudah disunting admin.
     *
     * ## Kenapa bentuknya begini
     *
     * Yang membaca postingan ini sedang menggulir cepat di antara puluhan
     * channel lain. Tiga detik pertama menentukan ia berhenti atau lewat,
     * jadi urutannya: judul yang menonjol, alasan untuk tertarik, baru
     * daftar episodenya.
     *
     * Baris keterangan penanda ada karena 🆓 dan 💎 tidak menjelaskan diri
     * sendiri kepada orang yang baru pertama kali melihat channel ini.
     *
     * Tautan "semua episode" di penutup penting untuk drama panjang: daftar
     * di caption terpotong pada episode ke sekian, dan tanpa satu tautan yang
     * membawa ke daftar utuh di bot, sisanya jadi tidak terjangkau dari
     * postingan ini.
     */
    public const TEMPLATE_BAWAAN = <<<'CAPTION'
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

    /** Definisi lengkap: kunci => [grup, label, tipe, bawaan, keterangan]. */
    public const SCHEMA = [
        // --- Umum ---
        'site_name'        => ['general', 'Nama situs', 'text', 'DramaVerse ID', null],
        'site_tagline'     => ['general', 'Tagline', 'text', 'Drama Asia, tanpa jeda.', null],
        'site_description' => ['general', 'Deskripsi', 'textarea', 'Platform streaming privat untuk drama Asia dengan subtitle Bahasa Indonesia.', 'Dipakai sebagai meta description bawaan.'],
        'logo'             => ['general', 'Logo', 'image', null, 'PNG atau WebP, tinggi ideal 64px.'],
        'favicon'          => ['general', 'Favicon', 'image', null, 'PNG persegi, 64×64 atau 128×128.'],

        // --- SEO ---
        'meta_keywords'    => ['seo', 'Kata kunci', 'text', null, 'Dipisahkan koma.'],
        'og_image'         => ['seo', 'Gambar berbagi', 'image', null, 'Tampil saat tautan dibagikan. Rasio 1200×630.'],

        // --- Kontak ---
        'contact_email'    => ['contact', 'Email kontak', 'text', null, null],
        'contact_telegram' => ['contact', 'Username Telegram', 'text', null, 'Tanpa tanda @.'],

        // --- Media sosial ---
        'social_instagram' => ['social', 'Instagram', 'text', null, 'URL lengkap.'],
        'social_twitter'   => ['social', 'X / Twitter', 'text', null, 'URL lengkap.'],
        'social_youtube'   => ['social', 'YouTube', 'text', null, 'URL lengkap.'],

        /*
        |----------------------------------------------------------------------
        | Channel Telegram
        |----------------------------------------------------------------------
        |
        | Postingan katalog ke channel publik. Dipisahkan dari
        | TELEGRAM_STORAGE_CHAT_ID di .env dengan sengaja: yang itu gudang
        | video privat yang tidak boleh berganti, yang ini etalase yang bisa
        | saja dipindah ke channel baru tanpa deploy.
        |
        */
        'channel_chat_id'  => ['channel', 'Chat ID channel', 'text', null, 'Contoh: -1001234567890 untuk channel, atau @namachannel. Bot harus jadi admin di sana.'],
        'channel_auto_post' => ['channel', 'Kirim otomatis saat drama dipublikasikan', 'boolean', '0', 'Satu drama hanya dikirim sekali; kiriman berikutnya harus lewat tombol manual.'],
        'channel_free_mark' => ['channel', 'Penanda episode gratis', 'text', '🆓', null],
        'channel_vip_mark'  => ['channel', 'Penanda episode VIP', 'text', '💎', null],
        'channel_cta'       => ['channel', 'Teks tautan', 'text', 'Tonton Sekarang', 'Kata yang jadi tautan ke bot di setiap baris.'],
        'channel_template'  => ['channel', 'Template caption', 'textarea',
            self::TEMPLATE_BAWAAN,
            'Placeholder: {judul}, {daftar}, {sinopsis}, {negara}, {genre}, {total_episode}, {tautan_drama}, {tautan_vip}. '
            .'Boleh memakai tag HTML Telegram: <b>, <i>, <u>, <s>, <code>, <blockquote>, <tg-spoiler>.'],
        'channel_line'      => ['channel', 'Format satu baris', 'text',
            '➤ <b>Part {nomor}</b> | {tanda} → {tautan}',
            'Placeholder: {nomor}, {tanda}, {tautan}, {judul_episode}.'],
        'channel_sinopsis_max' => ['channel', 'Batas panjang sinopsis', 'text', '180',
            'Caption foto Telegram maksimal 1024 karakter. Sinopsis yang panjang memakan jatah baris episode.'],

        // --- Sistem ---
        'footer_text'      => ['system', 'Teks footer', 'text', null, 'Kosongkan untuk memakai bawaan.'],
        'maintenance_mode' => ['system', 'Mode pemeliharaan', 'boolean', '0', 'Situs publik ditutup, panel admin tetap bisa diakses.'],
        'maintenance_text' => ['system', 'Pesan pemeliharaan', 'textarea', 'Kami sedang berbenah. Silakan kembali beberapa saat lagi.', null],
    ];

    /** Label tiap grup untuk ditampilkan di form. */
    public const GROUPS = [
        'general' => 'Umum',
        'seo'     => 'SEO',
        'contact' => 'Kontak',
        'social'  => 'Media sosial',
        'channel' => 'Channel Telegram',
        'system'  => 'Sistem',
    ];

    /** Seluruh pengaturan sebagai array kunci => nilai. */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $stored = Setting::pluck('value', 'key')->all();

            $result = [];

            foreach (self::SCHEMA as $key => [$group, $label, $type, $default, $hint]) {
                $result[$key] = $stored[$key] ?? $default;
            }

            return $result;
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** Menyimpan sekumpulan pengaturan sekaligus. */
    public function put(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::SCHEMA)) {
                continue;
            }

            [$group, $label, $type] = self::SCHEMA[$key];

            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'group' => $group, 'type' => $type]
            );
        }

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Definisi yang dikelompokkan, untuk merender form. */
    public function grouped(): array
    {
        $grouped = [];

        foreach (self::SCHEMA as $key => [$group, $label, $type, $default, $hint]) {
            $grouped[$group][] = compact('key', 'label', 'type', 'hint');
        }

        return $grouped;
    }
}
