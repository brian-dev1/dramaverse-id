<?php

namespace App\Support;

use App\Models\Drama;
use App\Models\Episode;
use App\Models\MembershipPlan;
use Illuminate\Support\HtmlString;

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

    /**
     * Membuat tagihan untuk satu paket: `?start=vip_12`.
     *
     * Inilah jembatan yang menggantikan daftar paket di dalam bot. Harganya
     * dipilih di website — di sana satu layar muat memuat seluruh paket
     * sekaligus, sesuatu yang tidak pernah bisa dilakukan deretan tombol
     * inline — lalu id paketnya dititipkan ke bot lewat parameter ini.
     *
     * Yang menyeberang hanya id-nya. Harga, masa aktif, dan wilayah
     * pembayaran tetap dibaca ulang dari basis data oleh
     * `PremiumHandler::buy()`, jadi tautan lama yang tersimpan di riwayat chat
     * seseorang tidak bisa membeli paket dengan harga kemarin.
     */
    public const PLAN = 'vip_';

    /** Tautan affiliate: `?start=ref_<kode>`. */
    public const REF = 'ref_';

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
     * Tautan "beli paket ini" untuk satu kartu harga di website.
     *
     * Null bila bot belum diatur — dan kartu tanpa tautan sebaiknya memang
     * tidak dirender sebagai tombol. Lihat `build()`.
     */
    public static function buyPlan(MembershipPlan|int $plan): ?string
    {
        $id = $plan instanceof MembershipPlan ? $plan->id : $plan;

        return self::build(self::PLAN.$id);
    }

    /** Tautan affiliate seseorang. Null bila bot belum diatur. */
    public static function referral(string $code): ?string
    {
        return self::build(self::REF.$code);
    }

    /**
     * Atribut `data-tg-href` untuk tombol tonton di situs.
     *
     * ## Kenapa atribut, bukan langsung dipasang di href
     *
     * Video hanya bisa diputar di dalam Telegram, tapi situs ini juga dibuka
     * dari browser biasa. Kalau `href` diisi tautan t.me, pengunjung desktop
     * yang menekan "Tonton Sekarang" dilempar keluar ke Telegram sebelum
     * sempat melihat sinopsisnya.
     *
     * Karena itu `href` tetap berisi alamat halaman biasa, dan tautan
     * Telegram menumpang di atribut terpisah. Di dalam Mini App, JavaScript
     * pada `partials/miniapp` mencegat kliknya dan membuka Telegram; di luar
     * itu — termasuk saat JavaScript mati — tombolnya bekerja seperti
     * biasa. Tidak ada keadaan di mana tombolnya menjadi mati.
     *
     * Mengembalikan HtmlString, bukan string biasa, supaya di Blade cukup
     * ditulis `{{ ... }}`. Blade tidak meng-escape ulang objek Htmlable, jadi
     * tanda kutip atributnya selamat tanpa perlu `{!! !!}` — dan `{!! !!}` di
     * dalam tag HTML adalah tempat lubang XSS biasanya masuk.
     *
     * `int` sengaja tidak diterima. Sebuah angka telanjang tidak menjelaskan
     * dirinya drama atau episode, dan menebaknya berarti suatu saat tombol
     * episode 12 mengantar ke drama nomor 12.
     */
    public static function attribute(Drama|Episode|null $tujuan): HtmlString
    {
        $tautan = match (true) {
            $tujuan instanceof Episode => self::watch($tujuan),
            $tujuan instanceof Drama   => self::drama($tujuan),
            default                    => null,
        };

        return new HtmlString(
            $tautan === null ? '' : 'data-tg-href="'.e($tautan).'"'
        );
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

    /**
     * Ambil kode affiliate dari parameter start.
     *
     * Menerima dua bentuk: `ref_<kode>` (yang dipakai sekarang) dan kode
     * telanjang yang diawali `ref`/`dv` — tautan lama yang sudah beredar di
     * grup orang tidak boleh mendadak berhenti menghasilkan komisi.
     */
    public static function referralCode(string $parameter): ?string
    {
        $kode = str_starts_with($parameter, self::REF)
            ? substr($parameter, strlen(self::REF))
            : $parameter;

        $kode = trim($kode);

        if (! preg_match('/^[A-Za-z0-9]{6,40}$/', $kode)) {
            return null;
        }

        return $kode;
    }

    public static function dramaId(string $parameter): ?int
    {
        return self::idAfter($parameter, self::DRAMA);
    }

    /**
     * Ambil id paket dari `vip_12`.
     *
     * Harus dibaca SEBELUM `referralCode()` di StartHandler. Bukan karena
     * keduanya bisa bentrok hari ini — garis bawah membuat `vip_12` gagal
     * pada regex kode affiliate — melainkan karena urutan itu adalah
     * satu-satunya yang tetap benar bila suatu saat regexnya dilonggarkan.
     */
    public static function planId(string $parameter): ?int
    {
        return self::idAfter($parameter, self::PLAN);
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
