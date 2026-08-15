<?php

namespace App\Enums;

/**
 * Kelompok berkas yang dikenali Storage Engine.
 *
 * Ini skema pengalamatan, bukan fitur upload. Tanpa enum ini setiap modul
 * yang memakai engine akan mengarang string direktorinya sendiri, dan cepat
 * atau lambat ada dua ejaan untuk tempat yang sama — `episode/thumbnail` di
 * satu tempat, `episode/thumb` di tempat lain. Berkasnya tersimpan, tapi
 * yang mencarinya tidak menemukannya.
 *
 * Direktori di bawah sengaja sama dengan yang sudah dipakai
 * `App\Services\Admin\MediaService` (disk `public`), supaya pemindahan modul
 * lama ke engine ini nanti tidak mengubah letak berkas yang sudah ada.
 *
 * Visibility adalah bagian terpenting di sini. Video episode dan subtitle
 * berada di balik langganan; menyimpannya sebagai publik berarti siapa pun
 * yang menebak URL bisa mengunduhnya tanpa membayar.
 */
enum StorageCollection: string
{
    case EPISODE = 'episode';

    case THUMBNAIL = 'thumbnail';

    case SUBTITLE = 'subtitle';

    case POSTER = 'poster';

    case COVER = 'cover';

    case BANNER = 'banner';

    case AVATAR = 'avatar';

    case ASSET = 'asset';

    public function label(): string
    {
        return match ($this) {
            self::EPISODE   => 'Video Part',
            self::THUMBNAIL => 'Thumbnail Part',
            self::SUBTITLE  => 'Subtitle',
            self::POSTER    => 'Poster Drama',
            self::COVER     => 'Cover Drama',
            self::BANNER    => 'Banner',
            self::AVATAR    => 'Avatar Pengguna',
            self::ASSET     => 'Aset Lain',
        };
    }

    /**
     * Direktori di dalam provider. Tanpa garis miring di awal maupun akhir.
     */
    public function directory(): string
    {
        return match ($this) {
            self::EPISODE   => 'episode/video',
            self::THUMBNAIL => 'episode/thumbnail',
            self::SUBTITLE  => 'episode/subtitle',
            self::POSTER    => 'drama/poster',
            self::COVER     => 'drama/cover',
            self::BANNER    => 'banner',
            self::AVATAR    => 'user/avatar',
            self::ASSET     => 'asset',
        };
    }

    /**
     * Berkas ini boleh dibaca umum tanpa autentikasi?
     *
     * `false` untuk video dan subtitle: keduanya isi berbayar. Berkas privat
     * diakses lewat temporary URL yang masa berlakunya pendek, bukan lewat
     * URL publik yang berlaku selamanya.
     */
    public function isPublic(): bool
    {
        return match ($this) {
            self::EPISODE, self::SUBTITLE => false,

            self::THUMBNAIL, self::POSTER, self::COVER,
            self::BANNER, self::AVATAR, self::ASSET => true,
        };
    }

    public function visibility(): string
    {
        return $this->isPublic() ? 'public' : 'private';
    }

    /**
     * Ekstensi yang diterima. Array kosong berarti tidak dibatasi.
     *
     * Selalu huruf kecil — pembandingnya di ObjectKey juga huruf kecil.
     */
    public function extensions(): array
    {
        return match ($this) {
            self::EPISODE   => ['mp4', 'mkv', 'webm', 'm4v', 'mov'],
            self::SUBTITLE  => ['srt', 'vtt', 'ass', 'ssa'],

            self::THUMBNAIL, self::POSTER, self::COVER,
            self::BANNER, self::AVATAR => ['jpg', 'jpeg', 'png', 'webp'],

            // Aset lain sengaja tidak dibatasi di lapisan enum. Pembatasannya
            // menjadi tanggung jawab modul yang memakainya, karena "aset lain"
            // memang tidak punya bentuk yang bisa ditebak di sini.
            self::ASSET => [],
        };
    }

    /**
     * Batas ukuran dalam kilobyte. `null` berarti tidak dibatasi engine.
     *
     * Catatan: batas ini TIDAK menggantikan `upload_max_filesize` dan
     * `post_max_size` di PHP, maupun `client_max_body_size` di Nginx. Berkas
     * yang melewati batas server ditolak sebelum PHP sempat berjalan, jadi
     * angka di sini hanya berlaku untuk berkas yang sudah sampai.
     */
    public function maxKb(): ?int
    {
        return match ($this) {
            self::EPISODE   => 8 * 1024 * 1024,   // 8 GB
            self::SUBTITLE  => 2 * 1024,          // 2 MB
            self::THUMBNAIL, self::POSTER, self::COVER,
            self::BANNER, self::AVATAR => 4 * 1024, // 4 MB, sama dengan MediaService
            self::ASSET     => null,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
