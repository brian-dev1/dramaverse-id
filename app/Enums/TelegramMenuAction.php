<?php

namespace App\Enums;

use App\Telegram\Handlers\ContinueWatchingHandler;
use App\Telegram\Handlers\FavoriteHandler;
use App\Telegram\Handlers\HelpHandler;
use App\Telegram\Handlers\HistoryHandler;
use App\Telegram\Handlers\LatestHandler;
use App\Telegram\Handlers\PremiumHandler;
use App\Telegram\Handlers\ProfileHandler;
use App\Telegram\Handlers\SearchHandler;
use App\Telegram\Handlers\TrendingHandler;
use App\Telegram\Handlers\WebsiteHandler;

/**
 * Perbuatan yang bisa dipasang pada satu tombol menu Telegram.
 *
 * Enum ini adalah **satu-satunya daftar** yang menghubungkan tiga hal:
 * pilihan di panel admin, `callback_data` yang dikirim Telegram, dan handler
 * yang menjalankannya. Sebelumnya ketiganya ditulis di tiga tempat terpisah —
 * `HomeKeyboard`, `CallbackHandler`, dan tidak ada tempat ketiga karena
 * panelnya memang belum ada — dan itulah sebabnya tombol Cari bisa hilang
 * dari menu tanpa ada yang menyadarinya.
 *
 * Menambah menu baru sekarang berarti menambah satu case di sini. Tanpa itu,
 * admin tidak akan bisa memilihnya, dan tombol yang tidak dikenal tidak akan
 * pernah dirender.
 */
enum TelegramMenuAction: string
{
    case SEARCH = 'search';
    case CONTINUE = 'continue';
    case FAVORITE = 'favorite';
    case HISTORY = 'history';
    case LATEST = 'latest';
    case TRENDING = 'trending';
    case WEBSITE = 'website';
    case PREMIUM = 'premium';
    case PROFILE = 'profile';
    case HELP = 'help';
    case URL = 'url';

    public function label(): string
    {
        return match ($this) {
            self::SEARCH   => 'Cari drama',
            self::CONTINUE => 'Lanjut menonton',
            self::FAVORITE => 'Favorit',
            self::HISTORY  => 'Riwayat tontonan',
            self::LATEST   => 'Episode terbaru',
            self::TRENDING => 'Sedang populer',
            self::WEBSITE  => 'Buka website (tautan masuk)',
            self::PREMIUM  => 'Premium',
            self::PROFILE  => 'Profil',
            self::HELP     => 'Bantuan',
            self::URL      => 'Tautan bebas (isi kolom URL)',
        };
    }

    /**
     * Kelas handler yang menjalankan perbuatan ini.
     *
     * Null untuk URL: tombol tautan dibuka langsung oleh Telegram dan tidak
     * pernah mengirim callback ke server kita.
     */
    public function handler(): ?string
    {
        return match ($this) {
            self::SEARCH   => SearchHandler::class,
            self::CONTINUE => ContinueWatchingHandler::class,
            self::FAVORITE => FavoriteHandler::class,
            self::HISTORY  => HistoryHandler::class,
            self::LATEST   => LatestHandler::class,
            self::TRENDING => TrendingHandler::class,
            self::WEBSITE  => WebsiteHandler::class,
            self::PREMIUM  => PremiumHandler::class,
            self::PROFILE  => ProfileHandler::class,
            self::HELP     => HelpHandler::class,
            self::URL      => null,
        };
    }

    /**
     * Apakah tombolnya berupa tautan, bukan callback.
     *
     * Tombol tautan WAJIB punya URL. Telegram menolak seluruh keyboard bila
     * ada satu tombol url dengan alamat kosong — bukan hanya tombol itu yang
     * hilang, tetapi seluruh menunya tidak muncul.
     */
    public function isLink(): bool
    {
        return $this === self::URL;
    }

    /**
     * SearchHandler dipanggil berbeda dari yang lain: ia memulai percakapan
     * dan menerima chat serta pengguna secara terpisah, bukan seluruh array
     * callback. Ditandai di sini supaya CallbackHandler tidak perlu
     * mengenali nama kelasnya.
     */
    public function startsConversation(): bool
    {
        return $this === self::SEARCH;
    }

    /** @return array<string,string> untuk dropdown di panel admin */
    public static function options(): array
    {
        $hasil = [];

        foreach (self::cases() as $case) {
            $hasil[$case->value] = $case->label();
        }

        return $hasil;
    }
}
