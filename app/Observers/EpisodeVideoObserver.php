<?php

namespace App\Observers;

use App\Enums\TelegramSyncStatus;
use App\Jobs\SyncEpisodeVideoToTelegram;
use App\Models\EpisodeVideo;
use App\Services\Telegram\TelegramCacheService;
use App\Services\Telegram\TelegramVideoSyncService;
use Illuminate\Support\Facades\Log;

/**
 * Dua pekerjaan yang harus terjadi setiap kali baris video berubah, tanpa
 * ada yang perlu mengingatnya.
 *
 * ## Kenapa observer, bukan panggilan di dalam service
 *
 * `EpisodeVideoService` (7.5) dan jalur antrean (7.7) sama-sama membuat baris
 * `episode_videos`, dan sejak 7.9 ada jalur ketiga lewat Batch Upload.
 * Menaruh "antrekan sinkronisasi" di salah satunya berarti dua jalur lain
 * diam-diam tidak melakukannya.
 *
 * Observer menangkap ketiganya sekaligus, dan — yang sama pentingnya —
 * **tidak mengubah satu baris pun** di modul-modul itu. Spesifikasi sprint ini
 * melarang mengubah fitur yang sudah berjalan.
 */
class EpisodeVideoObserver
{
    public function __construct(
        protected TelegramCacheService $cache,
        protected TelegramVideoSyncService $sync
    ) {
    }

    public function created(EpisodeVideo $video): void
    {
        $this->cache->forget($video->episode_id);

        $this->autoSync($video);
    }

    /**
     * Cache dibuang pada SETIAP perubahan, bukan hanya saat file_id berubah.
     *
     * Alasannya bukan kemalasan: `saved` juga menangkap perubahan
     * `object_key` saat berkas diganti, dan metadata episode yang ikut
     * di-cache tidak selalu punya kolom penanda sendiri. Membuang cache
     * terlalu sering hanya berbiaya satu query; membuangnya terlalu jarang
     * berarti bot mengirim video yang salah.
     */
    public function saved(EpisodeVideo $video): void
    {
        $this->cache->forget($video->episode_id);
    }

    public function deleted(EpisodeVideo $video): void
    {
        $this->cache->forget($video->episode_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Auto sync
    |--------------------------------------------------------------------------
    */

    /**
     * Antrekan sinkronisasi untuk video yang baru diunggah.
     *
     * Mati secara bawaan. Menyalakannya berarti setiap unggahan langsung
     * memakan kuota Telegram sebelum ada yang memutuskan berkas itu memang
     * akan disajikan lewat bot.
     *
     * Penolakan yang sudah pasti — berkas terlalu besar, chat penyimpanan
     * belum diisi — disaring lebih dulu lewat `blocker()`, alasan yang sama
     * persis dengan yang dipakai tombol Sync di panel. Mengantrekan pekerjaan
     * yang sudah pasti gagal hanya mengisi tabel dengan kegagalan yang bisa
     * diketahui sebelum dimulai.
     */
    private function autoSync(EpisodeVideo $video): void
    {
        if (! config('telegram.automation.auto_sync', false)) {
            return;
        }

        if ($video->sync_status !== TelegramSyncStatus::PENDING) {
            return;
        }

        if ($alasan = $this->sync->blocker($video)) {

            Log::channel(config('telegram.logging.channel') ?: config('logging.default'))
                ->info('telegram.autosync.skipped', [
                    'video_id' => $video->id,
                    'alasan'   => $alasan,
                ]);

            return;
        }

        SyncEpisodeVideoToTelegram::dispatch($video->id);

        Log::channel(config('telegram.logging.channel') ?: config('logging.default'))
            ->info('telegram.autosync.queued', ['video_id' => $video->id]);
    }
}
