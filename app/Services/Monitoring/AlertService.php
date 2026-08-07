<?php

namespace App\Services\Monitoring;

use App\Services\Telegram\Contracts\TelegramServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Support\Waktu;

/**
 * Pemberitahuan operator untuk seluruh sistem.
 *
 * ## Kenapa diangkat dari TelegramAlertService
 *
 * Sprint 8.9 membangun penahan peringatan di dalam `TelegramAlertService`.
 * Phase 9 butuh peringatan yang sama untuk backup, antrean, penyimpanan, dan
 * basis data — dan menyalin logika penahannya ke sana berarti empat salinan
 * dari satu aturan.
 *
 * Kelas ini yang sekarang memegang aturannya. `TelegramAlertService` tetap
 * ada sebagai kosakata khusus Telegram (`syncFailed`, `botOffline`) dan
 * meneruskan ke sini — jadi pemanggil lamanya tidak berubah sama sekali.
 *
 * ## Penahan
 *
 * Satu jenis peringatan dikirim sekali per `TELEGRAM_ALERT_THROTTLE` menit.
 * Bot atau server yang sedang bermasalah menghasilkan ratusan kegagalan yang
 * sama dalam hitungan menit; mengirim semuanya berarti yang membacanya akan
 * mematikan pemberitahuannya — persis kebalikan dari yang dimaksud.
 *
 * `Cache::add()` memeriksa DAN menandai dalam satu operasi, jadi tidak ada
 * celah di antaranya, dan penahannya berlaku lintas worker.
 *
 * **Log tidak ikut ditahan.** Jejak lengkap tetap perlu ada; yang dibatasi
 * hanya ketukan di bahu operator.
 */
class AlertService
{
    private const PREFIX = 'alert:';

    /** Peringatan yang selalu dikirim, seberapa pun seringnya. */
    public const CRITICAL = 'critical';

    public function __construct(
        protected TelegramServiceInterface $telegram
    ) {
    }

    /**
     * Kirim peringatan.
     *
     * @param  string  $kunci     jenis peringatan, dipakai penahan
     * @param  string  $judul     satu baris, dibaca operator lebih dulu
     * @param  string  $pesan     penjelasan beserta langkah berikutnya
     * @param  array   $context   ikut ke log, tidak ikut ke pesan Telegram
     * @param  bool    $kritis    lewati penahan
     */
    public function send(
        string $kunci,
        string $judul,
        string $pesan,
        array $context = [],
        bool $kritis = false
    ): void {

        $this->log($kunci, $judul, $context, $kritis);

        $chat = config('telegram.alerts.chat_id');

        if (blank($chat)) {
            return;
        }

        if (! $kritis && $this->ditahan($kunci)) {
            return;
        }

        try {
            $this->telegram->withRetries(1)->sendMessage(
                $chat,
                ($kritis ? '🔴 ' : '⚠️ ').'<b>'.e($judul)."</b>\n\n".e($pesan)
                    ."\n\n<i>".e((string) config('app.name')).' — '
                    .Waktu::ringkas(now()).'</i>'
            );

        } catch (Throwable) {

            // Peringatan yang gagal terkirim tidak boleh menggagalkan
            // pekerjaan yang sedang melaporkannya. Kalau Telegram sedang
            // tumbang, peringatan "Telegram tumbang" jelas juga tidak akan
            // sampai — dan melempar dari sini hanya menambah satu kegagalan
            // baru di atas kegagalan yang sudah ada.
            //
            // Sebabnya sudah dicatat TelegramClient.
        }
    }

    /** Peringatan yang tidak boleh ditahan. */
    public function critical(string $kunci, string $judul, string $pesan, array $context = []): void
    {
        $this->send($kunci, $judul, $pesan, $context, kritis: true);
    }

    /*
    |--------------------------------------------------------------------------
    | Bagian dalam
    |--------------------------------------------------------------------------
    */

    private function log(string $kunci, string $judul, array $context, bool $kritis): void
    {
        Log::channel(config('telegram.logging.channel') ?: config('logging.default'))
            ->log(
                $kritis ? 'critical' : 'warning',
                'alert.'.$kunci,
                $context + ['judul' => $judul]
            );
    }

    private function ditahan(string $kunci): bool
    {
        $menit = (int) config('telegram.alerts.throttle_minutes', 30);

        try {
            return ! Cache::add(self::PREFIX.$kunci, 1, $menit * 60);

        } catch (Throwable) {
            // Cache bermasalah: kirim saja. Peringatan berulang lebih baik
            // daripada peringatan yang hilang.
            return false;
        }
    }
}
