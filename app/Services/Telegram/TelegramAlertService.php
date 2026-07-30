<?php

namespace App\Services\Telegram;

use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\Telegram\Exceptions\TelegramException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Memberi tahu operator saat ada yang rusak.
 *
 * Dua saluran sekaligus: log selalu, dan pesan Telegram bila
 * `TELEGRAM_ALERT_CHAT_ID` diisi. Log adalah jejak yang bisa ditelusuri
 * belakangan; pesan Telegram adalah yang membangunkan orang.
 *
 * ## Kenapa ada penahan
 *
 * Bot yang sedang bermasalah akan menghasilkan ratusan kegagalan yang sama
 * dalam hitungan menit. Mengirim semuanya berarti yang membacanya akan
 * mematikan pemberitahuannya — persis kebalikan dari yang dimaksud.
 *
 * Satu jenis peringatan hanya dikirim sekali per `TELEGRAM_ALERT_THROTTLE`
 * menit. Penahannya di cache, jadi berlaku lintas worker. **Log tidak ikut
 * ditahan**: jejak lengkap tetap perlu ada, yang dibatasi hanya
 * ketukan di bahu operator.
 *
 * ## Kenapa kegagalan pengiriman peringatan ditelan
 *
 * Peringatan yang gagal terkirim tidak boleh menggagalkan pekerjaan yang
 * sedang melaporkannya. Kalau Telegram sedang tumbang, peringatan "Telegram
 * tumbang" jelas juga tidak akan sampai — dan melemparkan exception dari
 * sini hanya menambah satu kegagalan baru di atas kegagalan yang sudah ada.
 */
class TelegramAlertService
{
    private const PREFIX = 'telegram:alert:';

    public function __construct(
        protected TelegramServiceInterface $telegram
    ) {
    }

    /** Sinkronisasi satu video gagal. */
    public function syncFailed(int $videoId, string $sebab): void
    {
        $this->send(
            'sync-failed',
            'Sinkronisasi video gagal',
            "Video #{$videoId} gagal dikirim ke Telegram.\n\n".$sebab,
            ['video_id' => $videoId, 'sebab' => $sebab]
        );
    }

    /** Pekerjaan antrean berakhir di failed_jobs. */
    public function queueFailed(string $job, string $sebab): void
    {
        $this->send(
            'queue-failed',
            'Pekerjaan antrean gagal',
            "{$job} berhenti dengan galat.\n\n".$sebab,
            ['job' => $job, 'sebab' => $sebab]
        );
    }

    /** Telegram menolak, dan bukan karena kesalahan pengguna. */
    public function apiError(string $method, TelegramException $e): void
    {
        $this->send(
            'api-error-'.$method,
            'Telegram API menolak',
            "Method `{$method}` gagal.\n\n".$e->getMessage()
                .($e->hint() ? "\n\n".$e->hint() : ''),
            $e->logContext()
        );
    }

    /** Bot tidak menjawab getMe. */
    public function botOffline(string $sebab): void
    {
        $this->send(
            'bot-offline',
            'Bot tidak menjawab',
            "getMe gagal — bot tidak bisa dihubungi.\n\n".$sebab,
            ['sebab' => $sebab]
        );
    }

    /** Perintah terjadwal berhenti dengan galat. */
    public function schedulerError(string $perintah, string $sebab): void
    {
        $this->send(
            'scheduler-'.$perintah,
            'Perintah terjadwal gagal',
            "`{$perintah}` berhenti dengan galat.\n\n".$sebab,
            ['perintah' => $perintah, 'sebab' => $sebab]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Bagian dalam
    |--------------------------------------------------------------------------
    */

    /**
     * @param  string  $kunci  jenis peringatan, untuk penahan
     */
    public function send(string $kunci, string $judul, string $pesan, array $context = []): void
    {
        // Log selalu, tanpa penahan.
        Log::channel(config('telegram.logging.channel') ?: config('logging.default'))
            ->warning('telegram.alert.'.$kunci, $context + ['judul' => $judul]);

        $chat = config('telegram.alerts.chat_id');

        if (blank($chat) || $this->ditahan($kunci)) {
            return;
        }

        try {
            $this->telegram->withRetries(1)->sendMessage(
                $chat,
                "⚠️ <b>".e($judul)."</b>\n\n".e($pesan)
                    ."\n\n<i>".config('app.name').' — '.now()->format('d M Y H:i').'</i>'
            );
        } catch (Throwable) {
            // Sudah tercatat di log oleh client. Lihat alasannya di docblock.
        }
    }

    /** True bila peringatan jenis ini baru saja dikirim. */
    private function ditahan(string $kunci): bool
    {
        $menit = (int) config('telegram.alerts.throttle_minutes', 30);

        try {
            // add() mengembalikan false bila kuncinya sudah ada — satu
            // operasi untuk memeriksa DAN menandai, tanpa celah di antaranya.
            return ! Cache::add(self::PREFIX.$kunci, 1, $menit * 60);

        } catch (Throwable) {
            // Cache bermasalah: kirim saja. Peringatan berulang lebih baik
            // daripada peringatan yang hilang.
            return false;
        }
    }
}
