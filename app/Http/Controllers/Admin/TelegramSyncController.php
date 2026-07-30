<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TelegramSyncStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SyncEpisodeVideoToTelegram;
use App\Models\EpisodeVideo;
use App\Services\Admin\ActivityLogger;
use App\Services\Telegram\TelegramVideoSyncService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sinkronisasi video episode ke Telegram.
 *
 * Halaman ini tidak mengunggah apa pun dari komputer. Berkasnya sudah ada di
 * storage provider sejak modul unggah video (7.5); yang dikerjakan di sini
 * adalah menyalinnya ke Telegram sekali, lalu menyimpan `file_id`-nya.
 *
 * Seluruh pekerjaannya dijalankan lewat antrean. Pengiriman berkas ratusan
 * megabyte memakan menit, dan menahannya di dalam request admin adalah
 * kesalahan yang sudah diperbaiki di Sprint 7.7 untuk unggahan ke bucket —
 * tidak diulang di sini.
 */
class TelegramSyncController extends Controller
{
    /** Batas sekali tekan "Sinkronkan semua", supaya antrean tidak dibanjiri. */
    private const BATCH_LIMIT = 25;

    public function __construct(
        protected TelegramVideoSyncService $sync
    ) {
    }

    public function index(Request $request): View
    {
        $status = $request->query('status');

        $videos = EpisodeVideo::query()
            ->with(['episode.drama', 'provider'])
            ->when(
                $status !== null && TelegramSyncStatus::tryFrom($status) !== null,
                fn ($q) => $q->where('sync_status', $status)
            )
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('web.pages.admin.telegram-sync', [
            'videos'   => $videos,
            'status'   => $status,
            'statuses' => TelegramSyncStatus::cases(),
            'counts'   => $this->counts(),
            'blocker'  => blank(config('telegram.storage_chat_id'))
                ? 'TELEGRAM_STORAGE_CHAT_ID belum diisi di .env. Buat channel privat, '
                    .'jadikan bot sebagai admin, isikan id channelnya, lalu jalankan '
                    .'php artisan config:cache.'
                : null,
            'chatId'   => config('telegram.storage_chat_id'),
        ]);
    }

    public function sync(int $id): RedirectResponse
    {
        $video = EpisodeVideo::findOrFail($id);

        if ($alasan = $this->sync->blocker($video)) {
            return back()->with('error', $alasan);
        }

        SyncEpisodeVideoToTelegram::dispatch($video->id);

        app(ActivityLogger::class)->log('sync', 'telegram-video', $video->id);

        return back()->with(
            'status',
            'Sinkronisasi diantrekan. Pastikan worker antrean berjalan — '
            .'statusnya akan berubah sendiri di halaman ini.'
        );
    }

    public function retry(int $id): RedirectResponse
    {
        $video = EpisodeVideo::findOrFail($id);

        if ($video->sync_status !== TelegramSyncStatus::FAILED) {
            return back()->with('error', 'Hanya video yang gagal yang bisa diulang.');
        }

        $maks = (int) config('telegram.sync.max_retry', 3);

        if ($video->retry_count >= $maks) {
            return back()->with(
                'error',
                "Video ini sudah dicoba {$video->retry_count} kali dan tetap gagal. "
                .'Baca pesan galatnya dulu — mengulang tanpa mengubah apa pun akan '
                .'menghasilkan kegagalan yang sama.'
            );
        }

        // Status dikembalikan ke PENDING supaya job berikutnya lolos
        // penjagaan canStart(). Pengirimannya sendiri tetap di antrean.
        $video->forceFill(['sync_status' => TelegramSyncStatus::PENDING])->save();

        SyncEpisodeVideoToTelegram::dispatch($video->id);

        app(ActivityLogger::class)->log('retry', 'telegram-video', $video->id, [
            'percobaan' => $video->retry_count + 1,
        ]);

        return back()->with('status', 'Percobaan ulang diantrekan.');
    }

    /** Antrekan semua yang belum pernah tersinkron. */
    public function syncAll(): RedirectResponse
    {
        if (blank(config('telegram.storage_chat_id'))) {
            return back()->with('error', 'TELEGRAM_STORAGE_CHAT_ID belum diisi.');
        }

        $videos = EpisodeVideo::query()
            ->where('sync_status', TelegramSyncStatus::PENDING->value)
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->get();

        $diantre = 0;

        foreach ($videos as $video) {

            // Diperiksa satu per satu, bukan disaring di query: alasan
            // penolakan berbeda per berkas (provider mati, berkas terlalu
            // besar), dan menyaringnya di SQL akan melewatinya diam-diam.
            if ($this->sync->blocker($video) !== null) {
                continue;
            }

            SyncEpisodeVideoToTelegram::dispatch($video->id);

            $diantre++;
        }

        app(ActivityLogger::class)->log('sync', 'telegram-video', null, ['jumlah' => $diantre]);

        return back()->with('status', $diantre === 0
            ? 'Tidak ada video yang bisa diantrekan sekarang.'
            : "{$diantre} video diantrekan. Batas sekali tekan ".self::BATCH_LIMIT.' video.');
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /** @return array<string,int> */
    private function counts(): array
    {
        $hasil = [];

        foreach (TelegramSyncStatus::cases() as $status) {
            $hasil[$status->value] = EpisodeVideo::where('sync_status', $status->value)->count();
        }

        return $hasil;
    }
}
