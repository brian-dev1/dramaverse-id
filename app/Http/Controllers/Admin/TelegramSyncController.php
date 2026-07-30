<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TelegramSyncStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SyncEpisodeVideoToTelegram;
use App\Models\EpisodeVideo;
use App\Services\Admin\ActivityLogger;
use App\Services\Telegram\TelegramBulkService;
use App\Services\Telegram\TelegramHealthService;
use App\Services\Telegram\TelegramVideoSyncService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Telegram Media & Sync Management.
 *
 * Halaman ini tidak mengunggah apa pun dari komputer. Berkasnya sudah ada di
 * storage provider sejak modul unggah video (7.5); yang dikerjakan di sini
 * adalah menyalinnya ke Telegram sekali, lalu menyimpan `file_id`-nya.
 *
 * Seluruh pekerjaannya lewat antrean — termasuk aksi massal. Pengiriman
 * berkas ratusan megabyte memakan menit, dan menahannya di dalam request
 * admin adalah kesalahan yang sudah diperbaiki di Sprint 7.7 untuk unggahan
 * ke bucket; tidak diulang di sini.
 */
class TelegramSyncController extends Controller
{
    /** Batas sekali tekan "Sinkronkan yang menunggu". */
    private const BATCH_LIMIT = 25;

    /**
     * Kolom yang boleh diurutkan.
     *
     * Daftar tertutup, bukan menerima nama kolom apa pun dari query string.
     * Nama kolom yang datang dari luar dan langsung masuk ke `orderBy` adalah
     * jalan masuk untuk membocorkan struktur tabel lewat pesan galat SQL.
     */
    private const SORTABLE = [
        'id'          => 'id',
        'size'        => 'size',
        'status'      => 'sync_status',
        'synced_at'   => 'synced_at',
        'retry_count' => 'retry_count',
    ];

    public function __construct(
        protected TelegramVideoSyncService $sync,
        protected TelegramBulkService $bulk,
        protected TelegramHealthService $health
    ) {
    }

    public function index(Request $request): View
    {
        $sort = array_key_exists((string) $request->query('sort'), self::SORTABLE)
            ? (string) $request->query('sort')
            : 'id';

        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $videos = $this->filtered($request)
            ->with(['episode.drama', 'provider'])
            ->orderBy(self::SORTABLE[$sort], $dir)
            ->paginate(20)
            ->withQueryString();

        return view('web.pages.admin.telegram-sync', [
            'videos'   => $videos,
            'status'   => $request->query('status'),
            'q'        => $request->query('q'),
            'sort'     => $sort,
            'dir'      => $dir,
            'statuses' => TelegramSyncStatus::cases(),
            'health'   => $this->health->report(),
            'stats'    => $this->stats(),
            'blocker'  => $this->configBlocker(),
            'chatId'   => config('telegram.storage_chat_id'),
            'bulkMax'  => TelegramBulkService::LIMIT,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Aksi satuan
    |--------------------------------------------------------------------------
    */

    public function sync(int $id): RedirectResponse
    {
        $video = EpisodeVideo::findOrFail($id);

        if ($alasan = $this->sync->blocker($video)) {
            return back()->with('error', $alasan);
        }

        SyncEpisodeVideoToTelegram::dispatch($video->id);

        app(ActivityLogger::class)->log('sync', 'telegram-video', $video);

        return back()->with(
            'status',
            'Sinkronisasi diantrekan. Pastikan worker antrean berjalan — '
            .'statusnya akan berubah sendiri di halaman ini.'
        );
    }

    public function retry(int $id): RedirectResponse
    {
        $video = EpisodeVideo::findOrFail($id);

        $hasil = $this->bulk->retry([$video->id]);

        app(ActivityLogger::class)->log('retry', 'telegram-video', $video);

        return $hasil['queued'] > 0
            ? back()->with('status', 'Percobaan ulang diantrekan.')
            : back()->with('error', $hasil['skipped'][0] ?? 'Tidak bisa diulang.');
    }

    /** Antrekan semua yang belum pernah tersinkron. */
    public function syncAll(): RedirectResponse
    {
        if ($alasan = $this->configBlocker()) {
            return back()->with('error', $alasan);
        }

        $ids = EpisodeVideo::query()
            ->where('sync_status', TelegramSyncStatus::PENDING->value)
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->pluck('id')
            ->all();

        $hasil = $this->bulk->sync($ids);

        app(ActivityLogger::class)->log('sync', 'telegram-video', null, $hasil + ['massal' => true]);

        return $this->reportBulk($hasil, 'video diantrekan');
    }

    /*
    |--------------------------------------------------------------------------
    | Aksi massal
    |--------------------------------------------------------------------------
    */

    /**
     * Lima aksi lewat satu endpoint.
     *
     * Satu form, satu daftar kotak centang, satu tombol per aksi — dan
     * karenanya satu route. Memisahkannya jadi lima route berarti lima form
     * yang harus melingkupi tabel yang sama, dan form bersarang adalah bug
     * yang masih tercatat di STATUS.md untuk modul CRUD lain.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'aksi'  => ['required', 'string', 'in:sync,retry,cancel,refresh,verify'],
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ], [
            'ids.required' => 'Pilih dulu video yang ingin diproses.',
        ]);

        $hasil = match ($data['aksi']) {
            'sync'    => $this->bulk->sync($data['ids']),
            'retry'   => $this->bulk->retry($data['ids']),
            'cancel'  => $this->bulk->cancel($data['ids']),
            'refresh' => $this->bulk->refresh($data['ids']),
            'verify'  => $this->bulk->verify($data['ids']),
        };

        app(ActivityLogger::class)->log('bulk', 'telegram-video', null, [
            'aksi'  => $data['aksi'],
            'hasil' => $hasil,
        ]);

        return $this->reportBulk($hasil, match ($data['aksi']) {
            'sync'    => 'video diantrekan untuk sinkronisasi',
            'retry'   => 'video diantrekan ulang',
            'cancel'  => 'video dibatalkan',
            'refresh' => 'video disegarkan statusnya',
            'verify'  => 'video diantrekan untuk verifikasi file_id',
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Pencarian dan penyaringan.
     *
     * Pencarian menembus relasi ke judul drama dan nomor episode — dua hal
     * yang dipakai orang untuk menyebut sebuah video. Mencari berdasarkan
     * `object_key` tidak berguna bagi siapa pun yang tidak menghafal UUID.
     */
    private function filtered(Request $request): Builder
    {
        $status = (string) $request->query('status');

        $q = trim((string) $request->query('q'));

        return EpisodeVideo::query()
            ->when(
                TelegramSyncStatus::tryFrom($status) !== null,
                fn (Builder $b) => $b->where('sync_status', $status)
            )
            ->when($q !== '', function (Builder $b) use ($q) {

                $b->where(function (Builder $w) use ($q) {

                    $w->where('original_filename', 'like', "%{$q}%")
                        ->orWhereHas('episode', function (Builder $e) use ($q) {

                            $e->where('title', 'like', "%{$q}%")
                                ->orWhere('episode_number', $q)
                                ->orWhereHas(
                                    'drama',
                                    fn (Builder $d) => $d->where('title', 'like', "%{$q}%")
                                );
                        });
                });
            });
    }

    /** Angka ringkas untuk kartu statistik. */
    private function stats(): array
    {
        $sinkron = EpisodeVideo::whereNotNull('telegram_file_id');

        return [
            'total'       => EpisodeVideo::count(),
            'synced_size' => (int) (clone $sinkron)->sum('size'),
            'synced'      => (clone $sinkron)->count(),
            'stuck'       => $this->health->stuckQuery()->count(),
        ];
    }

    private function configBlocker(): ?string
    {
        return blank(config('telegram.storage_chat_id'))
            ? 'TELEGRAM_STORAGE_CHAT_ID belum diisi di .env. Buat channel privat, '
                .'jadikan bot sebagai admin, isikan id channelnya, lalu jalankan '
                .'php artisan config:cache.'
            : null;
    }

    /**
     * Laporkan hasil aksi massal apa adanya.
     *
     * Yang dilewati disebutkan beserta alasannya, bukan disembunyikan di
     * balik angka. "20 dari 25 diproses" tanpa penjelasan membuat admin
     * menekan tombolnya lagi dan lagi.
     */
    private function reportBulk(array $hasil, string $kalimat): RedirectResponse
    {
        $pesan = $hasil['queued'].' '.$kalimat.'.';

        if ($hasil['skipped'] !== []) {

            $tampil = array_slice($hasil['skipped'], 0, 5);

            $pesan .= ' Dilewati '.count($hasil['skipped']).': '.implode(' ', $tampil);

            if (count($hasil['skipped']) > 5) {
                $pesan .= ' (dan lainnya)';
            }
        }

        return $hasil['queued'] === 0 && $hasil['skipped'] !== []
            ? back()->with('error', $pesan)
            : back()->with('status', $pesan);
    }
}
