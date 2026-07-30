<?php

namespace App\Services\Telegram;

use App\Enums\TelegramSyncStatus;
use App\Models\EpisodeVideo;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\Telegram\Exceptions\TelegramException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Satu tempat yang menjawab "apakah lapisan Telegram sedang sehat".
 *
 * Dipakai tiga pemanggil: halaman admin, perintah `telegram:health` yang
 * dijalankan scheduler, dan `telegram:test`. Menulis pemeriksaannya di
 * masing-masing berarti tiga definisi "sehat" yang bisa berbeda — dan yang
 * ditampilkan panel akan mengatakan baik-baik saja sementara scheduler
 * mengirim peringatan.
 *
 * Seluruh method di sini **tidak pernah melempar**. Ini alat pemeriksa;
 * memeriksa keadaan yang rusak tidak boleh ikut rusak.
 */
class TelegramHealthService
{
    /** Batas waktu pendek: ini pemeriksaan, bukan pengiriman. */
    private const TIMEOUT = 6;

    public function __construct(
        protected TelegramServiceInterface $telegram
    ) {
    }

    /**
     * Seluruh keadaan sekaligus.
     *
     * @return array{bot:array, webhook:array, queue:array, sync:array, healthy:bool}
     */
    public function report(): array
    {
        $bot = $this->bot();

        $webhook = $this->webhook();

        $queue = $this->queue();

        $sync = $this->sync();

        return [
            'bot'     => $bot,
            'webhook' => $webhook,
            'queue'   => $queue,
            'sync'    => $sync,
            // Antrean yang menumpuk dan sinkronisasi yang gagal bukan
            // "tidak sehat": keduanya keadaan yang wajar dan tampil sebagai
            // angka. Yang menentukan sehat adalah bot bisa dihubungi.
            'healthy' => $bot['ok'],
        ];
    }

    /** Identitas bot. `ok` false berarti token salah atau jaringan mati. */
    public function bot(): array
    {
        if (! $this->telegram->isConfigured()) {
            return [
                'ok'    => false,
                'error' => 'TELEGRAM_BOT_TOKEN belum diisi.',
            ];
        }

        try {
            $me = $this->telegram->withTimeout(self::TIMEOUT)->withRetries(1)->getMe();

            return [
                'ok'          => true,
                'username'    => $me->get('username'),
                'name'        => $me->get('first_name'),
                'id'          => $me->get('id'),
                'duration_ms' => $me->durationMs,
            ];

        } catch (TelegramException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'hint' => $e->hint()];

        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function webhook(): array
    {
        if (! $this->telegram->isConfigured()) {
            return ['ok' => false, 'error' => 'Token belum diisi.'];
        }

        try {
            $info = $this->telegram->withTimeout(self::TIMEOUT)->withRetries(1)
                ->query('getWebhookInfo');

            return [
                'ok'         => true,
                'url'        => $info->get('url'),
                'pending'    => (int) $info->get('pending_update_count', 0),
                'last_error' => $info->get('last_error_message'),
            ];

        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Keadaan antrean.
     *
     * Hanya driver database yang jumlahnya bisa dibaca dari sini. Redis dan
     * SQS punya caranya sendiri, dan menebaknya lebih buruk daripada
     * menampilkan tanda hubung.
     */
    public function queue(): array
    {
        $connection = (string) config('queue.default');

        $antrean = (string) config('telegram.sync.queue', 'default');

        $hasil = [
            'connection' => $connection,
            'queue'      => $antrean,
            'pending'    => null,
            'failed'     => null,
        ];

        if ($connection !== 'database') {
            return $hasil;
        }

        try {
            $hasil['pending'] = DB::table('jobs')->where('queue', $antrean)->count();

            $hasil['failed'] = DB::table('failed_jobs')->count();

        } catch (Throwable) {
            // Tabel antrean belum dibuat. Bukan kegagalan pemeriksaan.
        }

        return $hasil;
    }

    /** Jumlah video per status sinkronisasi, plus yang tersangkut. */
    public function sync(): array
    {
        $hasil = ['stuck' => 0];

        try {
            foreach (TelegramSyncStatus::cases() as $status) {
                $hasil[$status->value] = EpisodeVideo::where('sync_status', $status->value)->count();
            }

            $hasil['stuck'] = $this->stuckQuery()->count();

        } catch (Throwable) {
            foreach (TelegramSyncStatus::cases() as $status) {
                $hasil[$status->value] = 0;
            }
        }

        return $hasil;
    }

    /**
     * Baris yang tertahan di status Diproses lebih lama dari batas wajar.
     *
     * Worker yang dibunuh paksa — restart supervisor, reboot, OOM killer —
     * tidak sempat menjalankan penanganan galat apa pun, dan barisnya akan
     * menghalangi percobaan berikutnya selamanya karena `canStart()` menolak
     * status PROCESSING.
     */
    public function stuckQuery()
    {
        $batas = now()->subMinutes((int) config('telegram.automation.stuck_minutes', 60));

        return EpisodeVideo::query()
            ->where('sync_status', TelegramSyncStatus::PROCESSING->value)
            ->where('updated_at', '<', $batas);
    }
}
