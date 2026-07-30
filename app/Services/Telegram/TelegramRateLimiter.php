<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Menahan laju permintaan ke Telegram sebelum Telegram yang menahannya.
 *
 * Bot API membatasi sekitar 30 pesan per detik untuk seluruh bot. Sampai
 * Sprint 8.6 yang ada hanya reaksi: kena 429, tunggu selama yang diminta,
 * coba lagi. Itu bekerja, tetapi mahal — satu broadcast ke ribuan penerima
 * menghabiskan waktu berkali-kali lebih lama karena separuh permintaannya
 * ditolak dulu sebelum diterima.
 *
 * ## Cara kerjanya
 *
 * Satu penghitung per detik di cache, dipakai bersama seluruh worker. Bila
 * kuota detik ini habis, pemanggil tidur sampai detik berikutnya. Sederhana,
 * dan cukup: yang dicegah adalah lonjakan, bukan pemakaian merata.
 *
 * ## Batasnya
 *
 * Ini **bukan** jaminan. Cache tanpa operasi atomik lintas proses (file,
 * array) bisa menghitung kurang saat dua worker menambah bersamaan. Yang
 * memberi jaminan tetap penanganan 429 di TelegramClient — pembatas ini
 * mengurangi seberapa sering itu terpakai, bukan menggantikannya.
 *
 * Karena itu juga ia tidak pernah menggagalkan permintaan. Menunggu terlalu
 * lama berarti menyerah menunggu dan tetap mengirim, membiarkan Telegram
 * yang memutuskan — permintaan yang ditolak masih lebih baik daripada
 * permintaan yang tidak pernah dikirim karena pembatas kita sendiri.
 */
class TelegramRateLimiter
{
    private const PREFIX = 'telegram:rate:';

    /**
     * Tunggu sampai ada kuota, lalu ambil satu.
     *
     * @return int milidetik yang benar-benar ditunggu, untuk log
     */
    public function acquire(): int
    {
        if (! config('telegram.rate_limit.enabled', true)) {
            return 0;
        }

        $perDetik = (int) config('telegram.rate_limit.per_second', 25);

        $maxWait = (int) config('telegram.rate_limit.max_wait_ms', 3000);

        $ditunggu = 0;

        while (true) {

            if ($this->tryConsume($perDetik)) {
                return $ditunggu;
            }

            // Menyerah menunggu: kirim saja, biar Telegram yang memutuskan.
            if ($ditunggu >= $maxWait) {
                return $ditunggu;
            }

            // Tidur sampai awal detik berikutnya, bukan interval tetap.
            // Kuotanya memang berganti per detik, jadi tidur 50 ms hanya
            // menghasilkan puluhan pemeriksaan yang semuanya gagal.
            $sisa = (int) ceil((1 - (microtime(true) - floor(microtime(true)))) * 1000);

            $tidur = max(10, min($sisa, $maxWait - $ditunggu));

            usleep($tidur * 1000);

            $ditunggu += $tidur;
        }
    }

    /**
     * Ambil satu kuota bila masih ada.
     *
     * Kegagalan cache diperlakukan sebagai "ada kuota". Pembatas laju yang
     * rusak tidak boleh menghentikan pengiriman — akibatnya jauh lebih besar
     * daripada masalah yang dicegahnya.
     */
    private function tryConsume(int $perDetik): bool
    {
        try {
            $kunci = self::PREFIX.(int) floor(microtime(true));

            // add() hanya menulis bila belum ada, jadi TTL-nya tidak
            // ter-reset oleh permintaan berikutnya di detik yang sama.
            Cache::add($kunci, 0, 2);

            return Cache::increment($kunci) <= $perDetik;

        } catch (Throwable) {
            return true;
        }
    }
}
