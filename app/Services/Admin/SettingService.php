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
            "『 {judul} 』\n\n{daftar}",
            'Placeholder: {judul}, {daftar}, {sinopsis}, {negara}, {total_episode}. Baris {daftar} diisi otomatis satu baris per episode.'],
        'channel_line'      => ['channel', 'Format satu baris', 'text',
            '➤ Part {nomor} | {tanda} → {tautan}',
            'Placeholder: {nomor}, {tanda}, {tautan}, {judul_episode}.'],

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
