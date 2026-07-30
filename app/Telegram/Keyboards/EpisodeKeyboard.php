<?php

namespace App\Telegram\Keyboards;

use App\Models\Episode;

/**
 * Tombol yang menempel di bawah video episode, dan daftar episode.
 *
 * ## Susunan `callback_data`
 *
 * Telegram membatasi `callback_data` sepanjang **64 byte**. Karena itu
 * awalannya sependek mungkin dan isinya id numerik:
 *
 *   `w:<episodeId>`            — putar episode
 *   `el:<dramaId>:<halaman>`   — daftar episode, halaman tertentu
 *   `fv:<dramaId>`             — tambah/hapus favorit
 *   `up`                       — buka penawaran premium
 *
 * Awalan dipisahkan dari nilai enum menu (`search`, `help`, dan seterusnya)
 * dengan titik dua, jadi keduanya tidak akan pernah bentrok: nilai enum tidak
 * pernah memuat titik dua.
 */
class EpisodeKeyboard
{
    public const WATCH = 'w';

    public const LIST = 'el';

    public const FAVORITE = 'fv';

    public const UPGRADE = 'up';

    /**
     * Tombol di bawah video: Previous, Daftar Episode, Next, Favorit, Website.
     *
     * Previous dan Next hanya dirender bila episodenya benar-benar ada.
     * Tombol yang menunjuk episode yang tidak ada adalah dead link versi
     * Telegram — sama dilarangnya dengan di web.
     */
    public static function player(Episode $episode, bool $isFavorite): array
    {
        $navigasi = [];

        if ($sebelum = $episode->previous()) {
            $navigasi[] = [
                'text'          => '◀ Sebelumnya',
                'callback_data' => self::WATCH.':'.$sebelum->id,
            ];
        }

        $navigasi[] = [
            'text'          => '📑 Daftar Episode',
            'callback_data' => self::LIST.':'.$episode->drama_id.':1',
        ];

        if ($sesudah = $episode->next()) {
            $navigasi[] = [
                'text'          => 'Berikutnya ▶',
                'callback_data' => self::WATCH.':'.$sesudah->id,
            ];
        }

        $baris = [$navigasi];

        $baris[] = [
            [
                'text'          => $isFavorite ? '💔 Hapus Favorit' : '❤️ Favorit',
                'callback_data' => self::FAVORITE.':'.$episode->drama_id,
            ],
        ];

        if ($website = self::websiteButton()) {
            $baris[] = [$website];
        }

        return ['inline_keyboard' => $baris];
    }

    /**
     * Daftar episode satu halaman.
     *
     * @param  \Illuminate\Support\Collection<int,Episode>  $episodes
     */
    public static function episodeList(
        int $dramaId,
        $episodes,
        int $halaman,
        int $totalHalaman
    ): array {

        $baris = [];

        $kolom = [];

        foreach ($episodes as $episode) {

            $kolom[] = [
                'text'          => 'Ep '.$episode->episode_number,
                'callback_data' => self::WATCH.':'.$episode->id,
            ];

            // Empat tombol per baris. Lebih dari itu, labelnya terpotong di
            // layar ponsel dan nomor episodenya tidak terbaca.
            if (count($kolom) === 4) {
                $baris[] = $kolom;

                $kolom = [];
            }
        }

        if ($kolom !== []) {
            $baris[] = $kolom;
        }

        $pindah = [];

        if ($halaman > 1) {
            $pindah[] = [
                'text'          => '◀ Halaman '.($halaman - 1),
                'callback_data' => self::LIST.':'.$dramaId.':'.($halaman - 1),
            ];
        }

        if ($halaman < $totalHalaman) {
            $pindah[] = [
                'text'          => 'Halaman '.($halaman + 1).' ▶',
                'callback_data' => self::LIST.':'.$dramaId.':'.($halaman + 1),
            ];
        }

        if ($pindah !== []) {
            $baris[] = $pindah;
        }

        $baris[] = [
            [
                'text'          => '❤️ Favorit',
                'callback_data' => self::FAVORITE.':'.$dramaId,
            ],
        ];

        return ['inline_keyboard' => $baris];
    }

    /** Tombol yang ditawarkan saat pengguna tidak punya akses. */
    public static function upgrade(): array
    {
        $baris = [
            [
                [
                    'text'          => '💎 Lihat Paket Premium',
                    'callback_data' => self::UPGRADE,
                ],
            ],
        ];

        if ($website = self::websiteButton()) {
            $baris[] = [$website];
        }

        return ['inline_keyboard' => $baris];
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Tombol "Buka Website".
     *
     * Memakai callback, bukan `url`. Tautan masuk ke situs dibuat per
     * pengguna dan berlaku sekali pakai — URL tetap yang ditempel di keyboard
     * akan sama untuk semua orang dan tidak memasukkan siapa pun.
     * WebsiteHandler yang membuatkannya saat tombolnya ditekan.
     */
    private static function websiteButton(): ?array
    {
        return [
            'text'          => '🌐 Buka Website',
            'callback_data' => 'website',
        ];
    }
}
