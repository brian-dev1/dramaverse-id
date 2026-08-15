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
            self::LATEST   => 'Part terbaru',
            self::TRENDING => 'Sedang populer',
            self::WEBSITE  => 'Buka website (tautan masuk sekali pakai)',
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
     * Tombol yang tidak boleh diubah, dinonaktifkan, atau dihapus.
     *
     * "Buka Website" adalah satu-satunya jalan pengguna masuk ke situs:
     * `WebsiteHandler` membuat token sekali pakai lalu mengirim tautan
     * masuknya. Tidak ada login email untuk pengguna biasa, jadi menghapus
     * tombol ini dari panel akan mengunci seluruh pengguna di luar situs —
     * dan yang menghapusnya tidak akan langsung tahu, karena bot-nya sendiri
     * tetap berjalan normal.
     *
     * Alamatnya juga tidak bisa diisi tangan. Tautan itu dibuat per pengguna
     * dan berlaku sekali pakai; URL tetap yang ditempel di sini akan sama
     * untuk semua orang dan tidak akan pernah bisa memasukkan siapa pun.
     *
     * "Profil" ikut dikunci sejak halaman profilnya berisi data sungguhan:
     * status langganan, sisa masa aktif, dan riwayat tontonan. Itu
     * satu-satunya tempat pengguna bisa memeriksa apakah pembayarannya sudah
     * masuk — menghapusnya dari menu berarti setiap pertanyaan "sudah aktif
     * belum?" berakhir di admin.
     */
    public function isLocked(): bool
    {
        return in_array($this, [self::WEBSITE, self::PROFILE], true);
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

    /**
     * Pilihan untuk dropdown di panel admin.
     *
     * Yang terkunci tidak ikut: ia sudah punya tombolnya sendiri yang tidak
     * bisa dihapus, dan menawarkannya lagi hanya membuat orang membuat
     * duplikat yang tidak berguna.
     *
     * @return array<string,string>
     */
    public static function options(): array
    {
        $hasil = [];

        foreach (self::cases() as $case) {

            if ($case->isLocked()) {
                continue;
            }

            $hasil[$case->value] = $case->label();
        }

        return $hasil;
    }
}
