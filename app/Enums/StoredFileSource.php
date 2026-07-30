<?php

namespace App\Enums;

use App\Models\DramaAsset;
use App\Models\EpisodeVideo;

/**
 * Dari tabel mana sebuah berkas di File Manager berasal.
 *
 * Sampai Sprint 7.7, berkas terunggah tersebar di dua tabel yang bentuknya
 * hampir identik: `episode_videos` (Sprint 7.5) dan `drama_assets` (7.6).
 * Keduanya menyimpan kolom yang sama — provider, object key, nama asli, nama
 * tersimpan, ukuran, checksum, url — dan berbeda hanya pada apa yang mereka
 * miliki: video milik satu episode, aset milik satu drama.
 *
 * File Manager perlu menampilkan keduanya dalam SATU daftar. Ada tiga cara
 * melakukannya, dan yang dipilih adalah yang ketiga:
 *
 * 1. Tabel registry baru yang ditulis semua modul. Paling rapi, tetapi
 *    mengharuskan `EpisodeVideoService` dan `DramaAssetService` diubah —
 *    yang dilarang spesifikasi sprint ini — dan berkas yang sudah terunggah
 *    sebelum registry ada tidak akan pernah terdaftar di sana.
 *
 * 2. Membaca isi bucket langsung. Menampilkan juga berkas yatim, tetapi
 *    kehilangan nama asli, tanggal unggah, dan pemiliknya — semuanya hanya
 *    ada di database — sekaligus membuat halaman bergantung pada provider
 *    yang bisa saja sedang tidak bisa dihubungi.
 *
 * 3. Membaca kedua tabel apa adanya lewat satu abstraksi. Nol tabel baru,
 *    nol duplikasi data, nol perubahan pada modul unggah. Enum inilah
 *    abstraksinya.
 *
 * Menambahkan modul berkas ketiga nanti berarti menambah satu case di sini
 * beserta empat method-nya; tidak ada tempat lain yang perlu tahu.
 */
enum StoredFileSource: string
{
    case EPISODE_VIDEO = 'episode_video';

    case DRAMA_ASSET = 'drama_asset';

    public function label(): string
    {
        return match ($this) {
            self::EPISODE_VIDEO => 'Video episode',
            self::DRAMA_ASSET   => 'Aset drama',
        };
    }

    public function table(): string
    {
        return match ($this) {
            self::EPISODE_VIDEO => 'episode_videos',
            self::DRAMA_ASSET   => 'drama_assets',
        };
    }

    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    public function model(): string
    {
        return match ($this) {
            self::EPISODE_VIDEO => EpisodeVideo::class,
            self::DRAMA_ASSET   => DramaAsset::class,
        };
    }

    /**
     * Relasi yang perlu ikut dimuat saat satu baris dibaca utuh.
     *
     * @return array<int, string>
     */
    public function relations(): array
    {
        return match ($this) {
            self::EPISODE_VIDEO => ['provider', 'episode.drama'],
            self::DRAMA_ASSET   => ['provider', 'drama'],
        };
    }

    /**
     * Berkas dari sumber ini boleh dihapus dari File Manager?
     *
     * Keduanya boleh, tetapi akibatnya berbeda dan halaman harus mengatakannya
     * sebelum admin menekan tombolnya: menghapus video episode membuat
     * episodenya kehilangan berkas dan tidak bisa diputar, sedangkan
     * menghapus aset drama hanya menghilangkan satu gambar.
     */
    public function deleteWarning(): string
    {
        return match ($this) {
            self::EPISODE_VIDEO => 'Episode ini akan kehilangan videonya dan tidak bisa '
                                   .'diputar sampai ada video baru yang diunggah.',
            self::DRAMA_ASSET   => 'Gambar ini akan hilang dari halaman yang memakainya.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::EPISODE_VIDEO => 'film',
            self::DRAMA_ASSET   => 'image',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
