<?php

namespace App\Services\Telegram;

use App\Enums\TelegramSyncStatus;
use App\Jobs\SyncEpisodeVideoToTelegram;
use App\Jobs\VerifyTelegramFileId;
use App\Models\EpisodeVideo;
use Illuminate\Support\Facades\Log;

/**
 * Aksi massal pada video episode.
 *
 * ## Kenapa terpisah dari controller
 *
 * Aturan "mana yang boleh disinkronkan" sudah ada di
 * `TelegramVideoSyncService::blocker()`. Aksi massal harus memakai aturan
 * yang sama persis — kalau tidak, tombol satuan dan tombol massal akan
 * berbeda pendapat tentang berkas yang sama, dan yang satu akan mengantrekan
 * pekerjaan yang sudah pasti gagal.
 *
 * Semuanya lewat antrean. Tidak ada satu pun byte video yang dikirim di
 * dalam request admin.
 *
 * ## Batas jumlah
 *
 * Setiap aksi dibatasi. Mengantrekan seribu pekerjaan dari satu klik akan
 * menyumbat antrean untuk semua hal lain — termasuk broadcast dan unggahan —
 * selama berjam-jam, dan tidak ada tombol untuk membatalkannya selain
 * mengosongkan tabel `jobs`.
 */
class TelegramBulkService
{
    public const LIMIT = 100;

    public function __construct(
        protected TelegramVideoSyncService $sync,
        protected TelegramCacheService $cache
    ) {
    }

    /**
     * Antrekan sinkronisasi untuk id yang dipilih.
     *
     * @param  array<int>  $ids
     * @return array{queued:int, skipped:array<string>}
     */
    public function sync(array $ids): array
    {
        $dilewati = [];

        $diantre = 0;

        foreach ($this->take($ids) as $video) {

            if ($alasan = $this->sync->blocker($video)) {
                $dilewati[] = "#{$video->id}: {$alasan}";

                continue;
            }

            SyncEpisodeVideoToTelegram::dispatch($video->id);

            $diantre++;
        }

        $this->log('bulk.sync', $diantre, count($dilewati));

        return ['queued' => $diantre, 'skipped' => $dilewati];
    }

    /**
     * Ulangi yang gagal.
     *
     * Yang sudah melewati `sync.max_retry` dilewati dengan alasan, bukan
     * diam-diam: mengulang tanpa mengubah apa pun akan menghasilkan
     * kegagalan yang sama, dan admin perlu tahu kenapa tombolnya tidak
     * berpengaruh pada baris itu.
     */
    public function retry(array $ids): array
    {
        $maks = (int) config('telegram.sync.max_retry', 3);

        $dilewati = [];

        $diantre = 0;

        foreach ($this->take($ids) as $video) {

            if ($video->sync_status !== TelegramSyncStatus::FAILED) {
                $dilewati[] = "#{$video->id}: statusnya bukan Gagal.";

                continue;
            }

            if ($video->retry_count >= $maks) {
                $dilewati[] = "#{$video->id}: sudah {$video->retry_count} kali gagal, "
                    .'baca pesan galatnya dulu.';

                continue;
            }

            $video->forceFill(['sync_status' => TelegramSyncStatus::PENDING])->save();

            SyncEpisodeVideoToTelegram::dispatch($video->id);

            $diantre++;
        }

        $this->log('bulk.retry', $diantre, count($dilewati));

        return ['queued' => $diantre, 'skipped' => $dilewati];
    }

    /**
     * Batalkan yang masih menunggu.
     *
     * Yang dibatalkan hanya baris yang belum dikerjakan worker. Pekerjaan
     * yang sudah berjalan TIDAK dihentikan di tengah jalan — memutus
     * pengiriman berkas separuh jalan meninggalkan berkas rusak di Telegram
     * yang tidak bisa dibedakan dari yang utuh.
     *
     * Baris PROCESSING yang benar-benar tersangkut dilepaskan oleh
     * `telegram:cleanup`, yang menunggu batas waktu wajar lebih dulu.
     */
    public function cancel(array $ids): array
    {
        $dilewati = [];

        $dibatalkan = 0;

        foreach ($this->take($ids) as $video) {

            if ($video->sync_status !== TelegramSyncStatus::PENDING) {
                $dilewati[] = "#{$video->id}: hanya yang berstatus Menunggu yang bisa dibatalkan.";

                continue;
            }

            $sebab = 'Dibatalkan admin dari panel.';

            $video->forceFill([
                'sync_status' => TelegramSyncStatus::FAILED,
                'last_error'  => $sebab,
            ])->save();

            $video->reportIssue($sebab);

            $dibatalkan++;
        }

        $this->log('bulk.cancel', $dibatalkan, count($dilewati));

        return ['queued' => $dibatalkan, 'skipped' => $dilewati];
    }

    /**
     * Lepaskan baris yang tersangkut, dan buang cache-nya.
     *
     * Tidak menyentuh Telegram sama sekali — yang dikerjakan hanya
     * menyegarkan keadaan di sisi kita.
     *
     * Termasuk menutup catatan masalah yang sudah tidak berlaku: baris yang
     * berstatus Tersinkron dan punya file_id tidak sedang bermasalah, dan
     * peringatannya di panel hanya sisa dari kegagalan yang sudah lewat.
     */
    public function refresh(array $ids): array
    {
        $disegarkan = 0;

        $batas = now()->subMinutes((int) config('telegram.automation.stuck_minutes', 60));

        foreach ($this->take($ids) as $video) {

            $this->cache->forget($video->episode_id);

            if ($video->isSyncedToTelegram() && $video->hasActiveIssue()) {
                $video->resolveIssue(
                    'Ditutup saat status disegarkan: video berstatus Tersinkron '
                    .'dan file_id-nya tersimpan.'
                );
            }

            if ($video->sync_status === TelegramSyncStatus::PROCESSING
                && $video->updated_at?->lt($batas)) {

                $sebab = 'Pekerjaan tersangkut di status Diproses dan dilepaskan '
                    .'saat status disegarkan. Worker kemungkinan berhenti sebelum selesai.';

                $video->forceFill([
                    'sync_status' => TelegramSyncStatus::FAILED,
                    'last_error'  => $sebab,
                ])->save();

                $video->reportIssue($sebab);
            }

            $disegarkan++;
        }

        $this->log('bulk.refresh', $disegarkan, 0);

        return ['queued' => $disegarkan, 'skipped' => []];
    }

    /**
     * Antrekan verifikasi `file_id`.
     *
     * Hanya untuk yang sudah tersinkron — tidak ada yang bisa diverifikasi
     * pada berkas yang belum pernah dikirim.
     */
    public function verify(array $ids): array
    {
        $dilewati = [];

        $diantre = 0;

        foreach ($this->take($ids) as $video) {

            if (! $video->isSyncedToTelegram()) {
                $dilewati[] = "#{$video->id}: belum punya file_id untuk diverifikasi.";

                continue;
            }

            VerifyTelegramFileId::dispatch($video->id);

            $diantre++;
        }

        $this->log('bulk.verify', $diantre, count($dilewati));

        return ['queued' => $diantre, 'skipped' => $dilewati];
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Ambil baris yang dipilih, dibatasi jumlahnya.
     *
     * @param  array<int>  $ids
     */
    private function take(array $ids)
    {
        $bersih = array_slice(
            array_values(array_unique(array_filter(array_map('intval', $ids)))),
            0,
            self::LIMIT
        );

        return $bersih === []
            ? collect()
            : EpisodeVideo::whereIn('id', $bersih)->orderBy('id')->get();
    }

    private function log(string $event, int $diproses, int $dilewati): void
    {
        if (! config('telegram.logging.enabled', true)) {
            return;
        }

        Log::channel(config('telegram.logging.channel') ?: config('logging.default'))
            ->info('telegram.'.$event, [
                'diproses' => $diproses,
                'dilewati' => $dilewati,
            ]);
    }
}
