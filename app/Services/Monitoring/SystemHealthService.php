<?php

namespace App\Services\Monitoring;

use App\Services\Backup\BackupService;
use App\Services\Storage\StorageMonitorService;
use App\Services\Telegram\TelegramHealthService;
use App\Support\LogFileReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Satu tempat yang menjawab "apakah sistem ini sedang sehat".
 *
 * ## Kenapa menggabung, bukan memeriksa sendiri
 *
 * Storage sudah punya `StorageMonitorService` (7.8) dan Telegram sudah punya
 * `TelegramHealthService` (8.9). Keduanya dipanggil dari sini, bukan
 * ditulis ulang — kalau ditulis ulang, dashboard ini bisa mengatakan sehat
 * sementara halaman Storage Monitoring mengatakan sebaliknya, dan tidak akan
 * ada yang tahu mana yang benar.
 *
 * Yang benar-benar baru di sini hanya empat: basis data, cache, scheduler,
 * dan server.
 *
 * ## Tidak pernah melempar
 *
 * Ini alat pemeriksa. Memeriksa keadaan yang rusak tidak boleh ikut rusak —
 * dashboard yang mati saat basis data bermasalah menghilangkan satu-satunya
 * halaman yang bisa memberi tahu apa yang terjadi.
 */
class SystemHealthService
{
    /**
     * Kunci cache detak scheduler.
     *
     * Diperbarui setiap kali `schedule:run` benar-benar dijalankan cron.
     * Tanpa penanda ini, scheduler yang tidak pernah berjalan sama sekali
     * terlihat persis sama dengan scheduler yang berjalan normal — tidak ada
     * galat, tidak ada log, tidak ada apa pun.
     */
    public const HEARTBEAT = 'scheduler:heartbeat';

    public function __construct(
        protected TelegramHealthService $telegram,
        protected StorageMonitorService $storage,
        protected BackupService $backup,
        protected LogFileReader $log
    ) {
    }

    /** Seluruh keadaan sekaligus. */
    public function report(): array
    {
        $bagian = [
            'database'  => $this->database(),
            'cache'     => $this->cache(),
            'queue'     => $this->queue(),
            'scheduler' => $this->scheduler(),
            'backup'    => $this->backupStatus(),
            'server'    => $this->server(),
            'telegram'  => $this->telegramStatus(),
            'storage'   => $this->storageStatus(),
            'errors'    => $this->errors(),
        ];

        // Sehat berarti tidak ada satu pun bagian yang berstatus `down`.
        // `warn` bukan tidak sehat: antrean yang menumpuk dan cadangan yang
        // agak tua adalah keadaan yang perlu dilihat, bukan keadaan darurat.
        $bagian['healthy'] = collect($bagian)
            ->filter(fn ($b) => is_array($b) && isset($b['status']))
            ->every(fn ($b) => $b['status'] !== 'down');

        return $bagian;
    }

    /*
    |--------------------------------------------------------------------------
    | Pemeriksaan
    |--------------------------------------------------------------------------
    */

    public function database(): array
    {
        try {
            $mulai = microtime(true);

            DB::connection()->getPdo();

            DB::select('select 1');

            $ms = (int) round((microtime(true) - $mulai) * 1000);

            return [
                'status'      => $ms > 1000 ? 'warn' : 'ok',
                'driver'      => config('database.default'),
                'duration_ms' => $ms,
                'pesan'       => $ms > 1000
                    ? 'Basis data menjawab, tetapi lambat.'
                    : 'Terhubung.',
            ];

        } catch (Throwable $e) {
            return [
                'status' => 'down',
                'driver' => config('database.default'),
                'pesan'  => $e->getMessage(),
            ];
        }
    }

    /**
     * Cache diperiksa dengan benar-benar menulis lalu membaca.
     *
     * Memeriksa `config('cache.default')` saja tidak membuktikan apa pun:
     * Redis yang mati tetap terkonfigurasi dengan benar sampai ada yang
     * mencoba memakainya.
     */
    public function cache(): array
    {
        $kunci = 'health:probe';

        try {
            $nilai = (string) now()->getTimestampMs();

            Cache::put($kunci, $nilai, 30);

            $kembali = Cache::get($kunci);

            Cache::forget($kunci);

            return $kembali === $nilai
                ? ['status' => 'ok', 'driver' => config('cache.default'), 'pesan' => 'Tulis dan baca berhasil.']
                : ['status' => 'down', 'driver' => config('cache.default'), 'pesan' => 'Nilai yang dibaca tidak sama dengan yang ditulis.'];

        } catch (Throwable $e) {
            return [
                'status' => 'down',
                'driver' => config('cache.default'),
                'pesan'  => $e->getMessage(),
            ];
        }
    }

    /** Antrean: jumlah menunggu, gagal, dan nama antreannya. */
    public function queue(): array
    {
        $connection = (string) config('queue.default');

        $hasil = [
            'status'     => 'ok',
            'connection' => $connection,
            'pending'    => null,
            'failed'     => null,
            'queues'     => [],
            'pesan'      => 'Jumlah tidak terbaca dari driver ini.',
        ];

        if ($connection !== 'database') {
            $hasil['status'] = 'unknown';

            return $hasil;
        }

        try {
            $hasil['pending'] = DB::table('jobs')->count();

            $hasil['failed'] = DB::table('failed_jobs')->count();

            $hasil['queues'] = DB::table('jobs')
                ->selectRaw('queue, count(*) as jumlah')
                ->groupBy('queue')
                ->pluck('jumlah', 'queue')
                ->all();

            $hasil['pesan'] = $hasil['failed'] > 0
                ? $hasil['failed'].' pekerjaan ada di failed_jobs. Periksa: php artisan queue:failed'
                : 'Tidak ada pekerjaan gagal.';

            // Pekerjaan gagal bukan keadaan darurat, tetapi selalu berarti
            // ada sesuatu yang berhenti bekerja tanpa ada yang tahu.
            $hasil['status'] = $hasil['failed'] > 0 ? 'warn' : 'ok';

        } catch (Throwable $e) {
            $hasil['status'] = 'down';

            $hasil['pesan'] = $e->getMessage();
        }

        return $hasil;
    }

    /**
     * Scheduler: kapan terakhir cron benar-benar memanggilnya.
     *
     * Ini pemeriksaan yang paling sering terlupa dipasang, dan paling mahal
     * saat terlupa: seluruh otomatisasi Telegram dan seluruh cadangan
     * bergantung padanya, dan tidak satu pun akan mengeluh bila cron-nya
     * tidak pernah ada.
     */
    public function scheduler(): array
    {
        try {
            $detak = Cache::get(self::HEARTBEAT);

        } catch (Throwable $e) {
            return ['status' => 'unknown', 'pesan' => 'Cache tidak bisa dibaca: '.$e->getMessage()];
        }

        if ($detak === null) {
            return [
                'status' => 'down',
                'pesan'  => 'Scheduler belum pernah berjalan. Pasang cron di server: '
                    .'* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
            ];
        }

        $menit = now()->diffInMinutes($detak);

        return [
            'status'   => $menit > 10 ? 'down' : 'ok',
            'terakhir' => $detak,
            'pesan'    => $menit > 10
                ? "Terakhir berjalan {$menit} menit lalu. Cron seharusnya memanggilnya tiap menit — periksa crontab."
                : 'Berjalan normal.',
        ];
    }

    public function backupStatus(): array
    {
        try {
            $umur = $this->backup->ageInHours();

            $maks = (int) config('backup.max_age_hours', 26);

            if ($umur === null) {
                return [
                    'status' => 'down',
                    'jumlah' => 0,
                    'pesan'  => 'Belum ada satu pun cadangan. Jalankan: php artisan backup:run',
                ];
            }

            return [
                'status'  => $umur > $maks ? 'warn' : 'ok',
                'jumlah'  => $this->backup->all()->count(),
                'umur'    => $umur,
                'ukuran'  => $this->backup->totalSize(),
                'terbaru' => $this->backup->latest(),
                'pesan'   => $umur > $maks
                    ? "Cadangan terbaru berumur {$umur} jam, melewati batas {$maks} jam."
                    : 'Cadangan terbaru masih segar.',
            ];

        } catch (Throwable $e) {
            return ['status' => 'unknown', 'jumlah' => 0, 'pesan' => $e->getMessage()];
        }
    }

    /** Ruang disk dan beban proses. */
    public function server(): array
    {
        $bebas = @disk_free_space(base_path());

        $total = @disk_total_space(base_path());

        $hasil = [
            'status'      => 'unknown',
            'disk_free'   => $bebas === false ? null : (int) $bebas,
            'disk_total'  => $total === false ? null : (int) $total,
            'php'         => PHP_VERSION,
            'memory_peak' => memory_get_peak_usage(true),
            'pesan'       => 'Ruang disk tidak terbaca.',
        ];

        if ($bebas === false || $total === false || $total <= 0) {
            return $hasil;
        }

        $persen = ($bebas / $total) * 100;

        $hasil['disk_percent'] = round($persen, 1);

        // Di bawah 5% praktis sudah tidak bisa dipakai: unggahan gagal,
        // cadangan gagal, dan MySQL berhenti menulis.
        $hasil['status'] = $persen < 5 ? 'down' : ($persen < 15 ? 'warn' : 'ok');

        $hasil['pesan'] = sprintf('Sisa %.1f%% ruang disk.', $persen);

        return $hasil;
    }

    /** Telegram, lewat service yang sudah ada sejak 8.9. */
    public function telegramStatus(): array
    {
        try {
            $laporan = $this->telegram->report();

            return [
                'status' => $laporan['healthy'] ? 'ok' : 'down',
                'bot'    => $laporan['bot'],
                'sync'   => $laporan['sync'],
                'pesan'  => $laporan['healthy']
                    ? 'Bot menjawab.'
                    : ($laporan['bot']['error'] ?? 'Bot tidak menjawab.'),
            ];

        } catch (Throwable $e) {
            return ['status' => 'unknown', 'pesan' => $e->getMessage()];
        }
    }

    /** Storage, lewat service yang sudah ada sejak 7.8. */
    public function storageStatus(): array
    {
        try {
            $snapshot = $this->storage->snapshot();

            $provider = $snapshot['providers'] ?? [];

            $aktif = (int) ($provider['active'] ?? 0);

            // `unusable` = aktif tetapi belum tentu bisa dipakai: adapter
            // composer belum terpasang, field wajib kosong, atau nilai
            // contohnya belum diganti. Ini angka yang menjelaskan kenapa
            // unggahan gagal padahal statusnya hijau.
            $bermasalah = (int) ($provider['unusable'] ?? 0);

            return [
                'status'  => $aktif === 0 ? 'down' : ($bermasalah > 0 ? 'warn' : 'ok'),
                'ringkas' => $provider,
                'files'   => $snapshot['files'] ?? [],
                'pesan'   => $aktif === 0
                    ? 'Tidak ada storage provider aktif. Seluruh unggahan akan gagal.'
                    : ($bermasalah > 0
                        ? $bermasalah.' provider aktif belum siap dipakai.'
                        : 'Seluruh provider aktif siap dipakai.'),
            ];

        } catch (Throwable $e) {
            return ['status' => 'unknown', 'pesan' => $e->getMessage()];
        }
    }

    /** Statistik galat dari ekor berkas log. */
    public function errors(): array
    {
        $hitung = $this->log->levelCounts();

        return [
            'status' => $hitung['error'] > 50 ? 'warn' : 'ok',
            'counts' => $hitung,
            'pesan'  => $hitung['error'] > 50
                ? $hitung['error'].' galat di potongan log terakhir. Periksa Log Sistem.'
                : $hitung['error'].' galat di potongan log terakhir.',
        ];
    }
}
