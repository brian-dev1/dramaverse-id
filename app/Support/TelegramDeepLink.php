<?php

namespace App\Support;

use App\Models\Drama;
use App\Models\Episode;

/**
 * Menyusun dan membaca tautan `t.me/<bot>?start=<parameter>`.
 *
 * Satu tempat untuk kedua arah. Website menyusunnya, bot membacanya, dan
 * keduanya memakai awalan yang sama persis — kalau susunannya ditulis dua
 * kali, tautan yang dibuat website suatu saat akan berhenti dikenali bot
 * tanpa ada yang mengubah keduanya bersamaan.
 *
 * ## Batas yang menentukan bentuknya
 *
 * Telegram membatasi parameter `start` sepanjang **64 karakter** dan hanya
 * menerima huruf, angka, garis bawah, dan tanda hubung. Karena itu yang
 * dikirim adalah id numerik, bukan slug: judul drama berbahasa Korea atau
 * Mandarin akan melewati batas itu sebelum sampai ke episodenya.
 */
class TelegramDeepLink
{
    public const WATCH = 'watch_';

    public const DRAMA = 'drama_';

    /** Membuka penawaran paket di bot. */
    public const SUBSCRIBE = 'subscribe';

    /*
    |--------------------------------------------------------------------------
    | Menyusun
    |--------------------------------------------------------------------------
    */

    /** Tautan menonton satu episode di bot. Null bila bot belum diatur. */
    public static function watch(Episode|int $episode): ?string
    {
        $id = $episode instanceof Episode ? $episode->id : $episode;

        return self::build(self::WATCH.$id);
    }

    /** Tautan membuka daftar episode satu drama di bot. */
    public static function drama(Drama|int $drama): ?string
    {
        $id = $drama instanceof Drama ? $drama->id : $drama;

        return self::build(self::DRAMA.$id);
    }

    /**
     * Tautan ke penawaran paket di bot.
     *
     * Sejak berlangganan dipindahkan sepenuhnya ke Telegram, inilah satu-satunya
     * jalan pengguna memulai pembayaran. Website hanya mengantar ke sini.
     */
    public static function subscribe(): ?string
    {
        return self::build(self::SUBSCRIBE);
    }

    /**
     * Tautan mentah dengan parameter apa pun.
     *
     * Null bila `TELEGRAM_BOT_USERNAME` belum diisi — tombol yang menunjuk
     * `t.me/?start=...` akan membuka Telegram tanpa bot apa pun, dan itu
     * lebih membingungkan daripada tombol yang tidak dirender sama sekali.
     */
    public static function build(string $parameter): ?string
    {
        $bot = trim((string) config('telegram.bot_username'), " \t@");

        if ($bot === '') {
            return null;
        }

        return 'https://t.me/'.$bot.'?start='.$parameter;
    }

    /*
    |--------------------------------------------------------------------------
    | Membaca
    |--------------------------------------------------------------------------
    */

    /**
     * Ambil id episode dari parameter start, atau null bila bukan tautan
     * menonton atau id-nya tidak masuk akal.
     */
    public static function episodeId(string $parameter): ?int
    {
        return self::idAfter($parameter, self::WATCH);
    }

    public static function dramaId(string $parameter): ?int
    {
        return self::idAfter($parameter, self::DRAMA);
    }

    private static function idAfter(string $parameter, string $prefix): ?int
    {
        if (! str_starts_with($parameter, $prefix)) {
            return null;
        }

        $sisa = substr($parameter, strlen($prefix));

        // ctype_digit menolak "12abc", "-4", dan "" sekaligus. Cast langsung
        // ke int akan mengubah ketiganya jadi angka yang terlihat sah.
        return ctype_digit($sisa) && (int) $sisa > 0 ? (int) $sisa : null;
    }
}
