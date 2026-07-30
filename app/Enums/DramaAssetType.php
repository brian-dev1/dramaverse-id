<?php

namespace App\Enums;

/**
 * Jenis aset yang bisa dimiliki sebuah drama.
 *
 * Enum ini adalah satu-satunya tempat yang menentukan direktori, ekstensi yang
 * diterima, batas ukuran, visibility, dan apakah sebuah jenis boleh punya lebih
 * dari satu berkas. Validasi form, daftar pilihan di panel, dan service upload
 * semuanya membacanya dari sini — jadi tidak mungkin form menjanjikan sesuatu
 * yang service tolak.
 *
 * Sengaja TIDAK menambah case ke `StorageCollection` (milik Sprint 7.4).
 * Storage Engine tidak boleh diubah di sprint ini, dan lagi pula kedua enum
 * menjawab pertanyaan berbeda: StorageCollection adalah skema alamat milik
 * engine, sedangkan enum ini aturan bisnis milik modul drama.
 */
enum DramaAssetType: string
{
    case POSTER = 'poster';

    case THUMBNAIL = 'thumbnail';

    case BANNER = 'banner';

    case BACKDROP = 'backdrop';

    case SUBTITLE = 'subtitle';

    case GALLERY = 'gallery';

    case LOGO = 'logo';

    case TRAILER_THUMBNAIL = 'trailer_thumbnail';

    case COVER_MOBILE = 'cover_mobile';

    case COVER_DESKTOP = 'cover_desktop';

    public function label(): string
    {
        return match ($this) {
            self::POSTER            => 'Poster',
            self::THUMBNAIL         => 'Thumbnail',
            self::BANNER            => 'Banner',
            self::BACKDROP          => 'Backdrop',
            self::SUBTITLE          => 'Subtitle',
            self::GALLERY           => 'Galeri',
            self::LOGO              => 'Logo Drama',
            self::TRAILER_THUMBNAIL => 'Thumbnail Trailer',
            self::COVER_MOBILE      => 'Cover Mobile',
            self::COVER_DESKTOP     => 'Cover Desktop',
        };
    }

    /**
     * Keterangan singkat untuk kartu di panel.
     */
    public function description(): string
    {
        return match ($this) {
            self::POSTER            => 'Gambar tegak untuk kartu katalog. Rasio 2:3.',
            self::THUMBNAIL         => 'Gambar kecil untuk daftar dan hasil pencarian.',
            self::BANNER            => 'Gambar lebar untuk sorotan di beranda.',
            self::BACKDROP          => 'Latar halaman detail, di belakang judul.',
            self::SUBTITLE          => 'Berkas teks terjemahan tingkat drama.',
            self::GALLERY           => 'Beberapa gambar sekaligus. Satu-satunya jenis yang boleh lebih dari satu.',
            self::LOGO              => 'Logo judul dengan latar transparan. PNG atau WebP.',
            self::TRAILER_THUMBNAIL => 'Gambar sampul untuk pemutar trailer.',
            self::COVER_MOBILE      => 'Sampul untuk layar sempit. Rasio tegak.',
            self::COVER_DESKTOP     => 'Sampul untuk layar lebar. Rasio melebar.',
        };
    }

    /**
     * Berkas gambar, bukan teks.
     */
    public function isImage(): bool
    {
        return $this !== self::SUBTITLE;
    }

    public function isSubtitle(): bool
    {
        return $this === self::SUBTITLE;
    }

    /**
     * Boleh punya lebih dari satu berkas.
     *
     * Hanya galeri. Untuk jenis lain, mengunggah berkas baru MENGGANTI yang
     * lama — kalau tidak, tidak ada yang bisa menjawab "poster mana yang
     * sedang dipakai drama ini".
     */
    public function allowsMultiple(): bool
    {
        return $this === self::GALLERY;
    }

    /**
     * Ekstensi yang diterima. Selalu huruf kecil.
     *
     * SVG sengaja TIDAK dimasukkan meskipun spesifikasi menyebutnya opsional.
     * SVG adalah dokumen XML yang boleh memuat <script>, dan berkas ini
     * disajikan dari domain yang sama dengan panel admin — satu SVG berisi
     * skrip yang dibuka langsung berarti skrip itu berjalan dengan sesi admin
     * yang sedang aktif. Menerimanya memerlukan pembersihan isi berkas lebih
     * dulu, dan itu pekerjaan tersendiri, bukan satu baris di daftar ini.
     */
    public function extensions(): array
    {
        return match ($this) {
            self::SUBTITLE => ['srt', 'vtt', 'ass', 'ssa'],

            self::POSTER, self::THUMBNAIL, self::BANNER, self::BACKDROP,
            self::GALLERY, self::LOGO, self::TRAILER_THUMBNAIL,
            self::COVER_MOBILE, self::COVER_DESKTOP => ['jpg', 'jpeg', 'png', 'webp', 'avif'],
        };
    }

    /**
     * Mimetype yang diterima, diperiksa dari ISI berkas bukan namanya.
     *
     * Daftar ini menahan berkas yang dinamai .png tetapi isinya bukan gambar.
     * Untuk subtitle nilainya longgar (`text/plain`) karena begitulah berkas
     * .srt dan .ass terbaca oleh pendeteksi mime — keduanya memang berkas teks
     * biasa, dan ekstensinya yang membedakan.
     */
    public function mimetypes(): array
    {
        return match ($this) {
            self::SUBTITLE => ['text/plain', 'text/vtt', 'application/x-subrip', 'text/x-ssa'],

            self::POSTER, self::THUMBNAIL, self::BANNER, self::BACKDROP,
            self::GALLERY, self::LOGO, self::TRAILER_THUMBNAIL,
            self::COVER_MOBILE, self::COVER_DESKTOP => [
                'image/jpeg', 'image/png', 'image/webp', 'image/avif',
            ],
        };
    }

    /**
     * Batas ukuran dalam kilobyte.
     *
     * Catatan: batas ini tidak menggantikan `upload_max_filesize` dan
     * `post_max_size` di PHP maupun `client_max_body_size` di Nginx. Yang
     * berlaku selalu yang terkecil.
     */
    public function maxKb(): int
    {
        return match ($this) {
            self::SUBTITLE => 2 * 1024,     // 2 MB, berkas teks

            // Backdrop dan cover desktop dipakai selebar layar, jadi wajar
            // lebih besar dari thumbnail.
            self::BACKDROP, self::COVER_DESKTOP, self::BANNER => 8 * 1024,

            self::POSTER, self::THUMBNAIL, self::GALLERY, self::LOGO,
            self::TRAILER_THUMBNAIL, self::COVER_MOBILE => 4 * 1024,
        };
    }

    /**
     * Semua aset drama bersifat publik.
     *
     * Berbeda dari video episode, yang privat karena berbayar. Poster dan
     * banner memang harus bisa dimuat peramban siapa pun tanpa autentikasi —
     * menjadikannya privat berarti setiap gambar di beranda perlu URL
     * bertanda tangan yang kedaluwarsa, dan halaman akan penuh gambar rusak
     * begitu tautannya lewat masa berlaku.
     *
     * Subtitle tingkat drama juga publik: isinya teks terjemahan, bukan
     * videonya. Subtitle per episode (yang mengikuti isi berbayar) bukan
     * bagian sprint ini.
     */
    public function visibility(): string
    {
        return 'public';
    }

    /**
     * Direktori di dalam provider, di bawah folder milik drama tersebut.
     *
     * Dikelompokkan per drama, bukan per jenis. Dengan begitu seluruh aset
     * satu drama berada di satu tempat — memudahkan saat harus diperiksa,
     * dipindahkan, atau dibersihkan bersama-sama.
     */
    public function directoryFor(int $dramaId): string
    {
        return sprintf('drama/%d/%s', $dramaId, $this->value);
    }

    /**
     * Ikon dari komponen <x-web.home.icon> untuk kartu di panel.
     */
    public function icon(): string
    {
        return match ($this) {
            self::SUBTITLE => 'file',
            self::GALLERY  => 'list',
            default        => 'image',
        };
    }

    /**
     * Urutan tampil di panel. Yang paling sering dipakai lebih dulu.
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::POSTER            => 10,
            self::COVER_DESKTOP     => 20,
            self::COVER_MOBILE      => 30,
            self::BACKDROP          => 40,
            self::BANNER            => 50,
            self::THUMBNAIL         => 60,
            self::LOGO              => 70,
            self::TRAILER_THUMBNAIL => 80,
            self::GALLERY           => 90,
            self::SUBTITLE          => 100,
        };
    }

    /**
     * Semua jenis, urut tampil.
     *
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        $cases = self::cases();

        usort($cases, fn (self $a, self $b) => $a->sortOrder() <=> $b->sortOrder());

        return $cases;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        $options = [];

        foreach (self::ordered() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
