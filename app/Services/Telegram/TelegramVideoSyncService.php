<?php

namespace App\Services\Telegram;

use App\Enums\TelegramSyncStatus;
use App\Models\EpisodeVideo;
use App\Services\Storage\Contracts\StorageEngineInterface;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\Telegram\Exceptions\TelegramException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Memindahkan video episode dari storage provider ke Telegram, satu kali.
 *
 * ## Kenapa hanya sekali
 *
 * Telegram menyimpan berkas yang pernah dikirim dan memberi `file_id`.
 * Mengirim ulang video ke pengguna lain cukup dengan menyebut file_id itu —
 * tidak ada byte yang keluar dari server kita, tidak ada bandwidth bucket
 * yang terpakai, dan pengirimannya selesai dalam hitungan milidetik alih-alih
 * menit.
 *
 * Karena itu sinkronisasi adalah operasi sekali seumur berkas, dan status
 * SYNCED menolak permintaan sinkronisasi berikutnya. Mengirim ulang isi yang
 * sama hanya menghasilkan file_id kedua untuk berkas yang sama.
 *
 * ## Alurnya
 *
 * 1. Baca berkas dari storage provider lewat StorageEngineInterface —
 *    **bukan** dari komputer siapa pun. Berkasnya sudah ada di bucket sejak
 *    Sprint 7.5.
 * 2. Alirkan ke berkas sementara di disk server. Ini perlu karena Bot API
 *    mengunggah multipart dan butuh berkas yang bisa dibaca ulang saat
 *    percobaan diulang.
 * 3. Kirim ke chat penyimpanan.
 * 4. Simpan file_id, buang berkas sementaranya.
 *
 * Langkah 2 memakai ruang disk sementara sebesar berkasnya. Itu harga yang
 * dibayar untuk bisa mengulang tanpa mengunduh lagi dari bucket, dan
 * berkasnya selalu dihapus di blok `finally` — termasuk saat gagal.
 */
class TelegramVideoSyncService
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected StorageEngineInterface $storage,
        protected TelegramAlertService $alerts
    ) {
    }

    /**
     * Apakah sinkronisasi bisa dimulai sekarang, atau alasan kenapa tidak.
     *
     * Dipisahkan dari sync() supaya panel bisa menonaktifkan tombolnya
     * dengan alasan yang sama persis dengan yang akan ditolak service.
     */
    public function blocker(EpisodeVideo $video): ?string
    {
        if (blank(config('telegram.storage_chat_id'))) {
            return 'TELEGRAM_STORAGE_CHAT_ID belum diisi. Buat channel privat, '
                .'jadikan bot sebagai admin, lalu isikan id channelnya.';
        }

        if (! $video->isReachable()) {
            return 'Storage provider berkas ini tidak bisa dijangkau — provider '
                .'nonaktif, terhapus, atau kredensialnya belum lengkap.';
        }

        if (! $video->sync_status->canStart()) {
            return $video->sync_status === TelegramSyncStatus::SYNCED
                ? 'Video ini sudah tersinkron. Mengirim ulang hanya menghasilkan '
                    .'file_id kedua untuk berkas yang sama.'
                : 'Sinkronisasi video ini sedang berjalan.';
        }

        $maxMb = (int) config('telegram.upload_max_mb', 50);

        if ($video->size > $maxMb * 1048576) {
            return sprintf(
                'Berkas %s MB melewati batas unggah Bot API (%d MB). Batas ini '
                .'milik Telegram, bukan aplikasi. Jalan keluarnya memakai Local '
                .'Bot API Server sendiri lewat TELEGRAM_API_URL, yang batasnya '
                .'2000 MB.',
                number_format($video->size / 1048576, 1),
                $maxMb
            );
        }

        return null;
    }

    /**
     * Jalankan sinkronisasi sekarang juga.
     *
     * Melempar bila gagal, supaya job antrean bisa memutuskan sendiri apakah
     * pantas diulang. Status dan `last_error` sudah tersimpan sebelum
     * exception dilempar.
     *
     * @throws RuntimeException|TelegramException
     */
    public function sync(EpisodeVideo $video): EpisodeVideo
    {
        // Idempotency guard: jangan ubah video yang sudah sukses menjadi FAILED.
        if ($video->isSyncedToTelegram()) {
            // Berkasnya sudah punya file_id yang sah. Kalau masih ada catatan
            // masalah lama yang menempel, itu sisa dari percobaan sebelumnya
            // dan bukan keadaan sekarang — ditutup di sini, supaya panel
            // tidak memasang tanda peringatan pada baris yang sudah beres.
            $video->resolveIssue(
                'Video sudah tersinkron dengan file_id yang sah saat permintaan '
                .'sinkronisasi berikutnya diperiksa.'
            );

            $this->log('info', 'sync.already_synced', $video);

            return $video->refresh();
        }

        if ($alasan = $this->blocker($video)) {
            $this->fail($video, $alasan, naikkanRetry: false);
            $video->reportIssue($alasan);

            throw new RuntimeException($alasan);
        }

        $video->forceFill([
            'sync_status' => TelegramSyncStatus::PROCESSING,
            'last_error'  => null,
        ])->save();

        $this->log('info', 'sync.started', $video);

        $temp = null;

        try {
            $temp = $this->pullToTempFile($video);

            $response = $this->telegram
                ->withTimeout((int) config('telegram.sync.timeout', 1800))
                ->withRetries(1)
                ->sendVideo(
                    config('telegram.storage_chat_id'),
                    new SplFileInfo($temp),
                    $this->caption($video),
                    ['supports_streaming' => true]
                );

            return $this->succeed($video, $response);

        } catch (Throwable $e) {

            $sebab = $this->reason($e);

            $this->fail($video, $sebab);
            $video->reportIssue($sebab);

            $this->log('error', 'sync.failed', $video, ['sebab' => $sebab]);

            // Operator diberi tahu. Penahannya ada di TelegramAlertService:
            // sepuluh video yang gagal karena sebab yang sama tidak menjadi
            // sepuluh pesan.
            $this->alerts->syncFailed($video->id, $sebab);

            throw $e;

        } finally {

            // Selalu, termasuk saat gagal. Berkas video berukuran ratusan
            // megabyte; sepuluh kegagalan yang meninggalkan sisa sudah cukup
            // untuk memenuhi disk VPS.
            if ($temp !== null && is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Ulangi sinkronisasi yang gagal.
     *
     * Yang diulang hanya pengiriman ke Telegram. Berkasnya diambil lagi dari
     * bucket — **tidak pernah** dari komputer siapa pun, dan tidak perlu ada
     * yang mengunggah ulang apa pun.
     */
    public function retry(EpisodeVideo $video): EpisodeVideo
    {
        // Retry terhadap video yang sudah punya file_id harus menjadi no-op.
        if ($video->isSyncedToTelegram()) {
            $video->resolveIssue(
                'Percobaan ulang dilewati karena video sudah tersinkron dengan '
                .'file_id yang sah.'
            );

            $this->log('info', 'sync.retry_skipped_already_synced', $video);

            return $video->refresh();
        }

        $video->forceFill([
            'sync_status' => TelegramSyncStatus::PENDING,
            'last_error'  => null,
        ])->save();

        $this->log('info', 'sync.retry', $video, [
            'percobaan' => $video->retry_count + 1,
        ]);

        return $this->sync($video);
    }

    /*
    |--------------------------------------------------------------------------
    | Bagian dalam
    |--------------------------------------------------------------------------
    */

    /**
     * Alirkan berkas dari storage provider ke berkas sementara di server.
     *
     * Dialirkan per potongan, bukan dibaca sekaligus: `file_get_contents` pada
     * video 400 MB menaikkan pemakaian memori PHP sebesar itu juga, dan
     * `memory_limit` worker akan menghentikannya di tengah jalan.
     */
    private function pullToTempFile(EpisodeVideo $video): string
    {
        $sumber = $this->storage->readStream(
            $video->storage_provider_id,
            $video->object_key
        );

        if (! is_resource($sumber)) {
            throw new RuntimeException(
                "Berkas `{$video->object_key}` tidak bisa dibaca dari storage provider."
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'tgsync_');

        if ($path === false) {
            throw new RuntimeException('Tidak bisa membuat berkas sementara di server.');
        }

        $tujuan = fopen($path, 'w');

        try {
            if ($tujuan === false) {
                throw new RuntimeException("Berkas sementara {$path} tidak bisa ditulis.");
            }

            stream_copy_to_stream($sumber, $tujuan);
        } finally {
            if (is_resource($tujuan)) {
                fclose($tujuan);
            }

            if (is_resource($sumber)) {
                fclose($sumber);
            }
        }

        return $path;
    }

    private function succeed(EpisodeVideo $video, TelegramResponse $response): EpisodeVideo
    {
        // Telegram bisa menjawab dengan objek `video` atau `document`,
        // tergantung apakah berkasnya dikenali sebagai video yang bisa
        // diputar. Keduanya membawa file_id, dan keduanya sah untuk dikirim
        // ulang — jadi keduanya diterima.
        $berkas = $response->get('video') ?? $response->get('document');

        $fileId = $berkas['file_id'] ?? null;

        if (blank($fileId)) {
            throw new RuntimeException(
                'Telegram menerima berkasnya tetapi tidak mengembalikan file_id. '
                .'Tanpa file_id, video tidak bisa dikirim ulang tanpa mengunggah lagi.'
            );
        }

        $video->forceFill([
            'telegram_file_id'        => $fileId,
            'telegram_unique_file_id' => $berkas['file_unique_id'] ?? null,
            'telegram_message_id'     => $response->messageId(),
            'sync_status'             => TelegramSyncStatus::SYNCED,
            'synced_at'               => now(),
            'last_error'              => null,
        ])->save();

        /*
         * Sinkronisasi yang berhasil ADALAH bukti sehat.
         *
         * Telegram menerima berkasnya dan mengembalikan file_id baru — tidak
         * ada lagi yang tersisa dari kegagalan sebelumnya untuk dibuktikan.
         * Sebelum ini catatan masalahnya dibiarkan terbuka dan panel terus
         * memasang "⚠ Masih ada masalah" pada baris yang justru baru saja
         * selesai, sampai ada yang menekan Verifikasi file_id secara manual.
         */
        $video->resolveIssue(
            'Sinkronisasi ulang berhasil dan Telegram mengembalikan file_id baru.'
        );

        $this->log('info', 'sync.success', $video, [
            'duration_ms' => $response->durationMs,
        ]);

        return $video->refresh();
    }

    private function fail(EpisodeVideo $video, string $sebab, bool $naikkanRetry = true): void
    {
        $video->forceFill([
            'sync_status' => TelegramSyncStatus::FAILED,
            'last_error'  => $sebab,
            'retry_count' => $naikkanRetry ? $video->retry_count + 1 : $video->retry_count,
        ])->save();
    }

    private function reason(Throwable $e): string
    {
        if ($e instanceof TelegramException) {
            return trim($e->getMessage().' '.($e->hint() ?? ''));
        }

        return $e->getMessage() ?: $e::class;
    }

    private function caption(EpisodeVideo $video): string
    {
        $episode = $video->episode;

        return sprintf(
            "%s\nEpisode %s\nid:%d",
            e($episode?->drama?->title ?? 'Drama'),
            e((string) ($episode?->episode_number ?? '?')),
            $video->episode_id
        );
    }

    private function log(string $level, string $event, EpisodeVideo $video, array $extra = []): void
    {
        if (! config('telegram.logging.enabled', true)) {
            return;
        }

        Log::channel(config('telegram.logging.channel') ?: config('logging.default'))
            ->log($level, 'telegram.'.$event, $extra + [
                'video_id'    => $video->id,
                'episode_id'  => $video->episode_id,
                'provider_id' => $video->storage_provider_id,
                'size'        => $video->size,
                'retry_count' => $video->retry_count,
            ]);
    }
}
