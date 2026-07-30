<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupService;
use App\Services\Monitoring\SystemHealthService;
use App\Services\Admin\ActivityLogger;
use App\Support\LogFileReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Dashboard kesehatan sistem, dan pengelolaan cadangan.
 *
 * Halaman ini menggabungkan pemeriksaan yang sudah ada — Storage Monitoring
 * (7.8) dan Telegram Health (8.9) — dengan pemeriksaan basis data, cache,
 * antrean, scheduler, cadangan, dan server. Tidak ada satu pun yang ditulis
 * ulang; kalau ditulis ulang, dashboard ini bisa mengatakan sehat sementara
 * halaman aslinya mengatakan sebaliknya.
 */
class MonitoringController extends Controller
{
    public function __construct(
        protected SystemHealthService $health,
        protected BackupService $backup,
        protected LogFileReader $log
    ) {
    }

    public function index(): View
    {
        return view('web.pages.admin.monitoring', [
            'health'   => $this->health->report(),
            'backups'  => $this->backup->all()->take(10),
            'logSize'  => $this->log->size(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Cadangan
    |--------------------------------------------------------------------------
    */

    /**
     * Jalankan cadangan sekarang.
     *
     * Lewat antrean, bukan di dalam request: `mysqldump` pada basis data yang
     * sudah besar memakan menit, dan menahannya di request admin akan
     * berakhir dengan galat gateway sementara prosesnya tetap berjalan di
     * belakang — keadaan paling membingungkan yang bisa terjadi.
     */
    public function backupNow(): RedirectResponse
    {
        \App\Jobs\RunBackupJob::dispatch();

        app(ActivityLogger::class)->log('backup', 'monitoring', null, ['dipicu' => 'manual']);

        return back()->with(
            'status',
            'Cadangan diantrekan. Pastikan worker antrean berjalan — '
            .'berkasnya akan muncul di daftar bawah setelah selesai.'
        );
    }

    /**
     * Verifikasi satu cadangan.
     *
     * Nama berkas divalidasi ketat, bukan dipakai apa adanya: nama yang
     * datang dari luar dan langsung digabung ke path adalah jalan untuk
     * membaca berkas mana pun di server lewat `../`.
     */
    public function verify(Request $request): RedirectResponse
    {
        $path = $this->resolve((string) $request->input('nama'));

        if ($path === null) {
            return back()->with('error', 'Berkas cadangan tidak ditemukan.');
        }

        $hasil = $this->backup->verify($path);

        return back()->with(
            $hasil['ok'] ? 'status' : 'error',
            basename($path).' — '.$hasil['pesan']
        );
    }

    public function download(Request $request): BinaryFileResponse|RedirectResponse
    {
        $path = $this->resolve((string) $request->query('nama'));

        if ($path === null) {
            return back()->with('error', 'Berkas cadangan tidak ditemukan.');
        }

        app(ActivityLogger::class)->log('unduh', 'backup', null, ['berkas' => basename($path)]);

        // Berkas ini memuat .env dalam bentuk teks polos. Yang mengunduhnya
        // tercatat di activity_logs, dan itu disengaja.
        return response()->download($path);
    }

    public function prune(): RedirectResponse
    {
        $dihapus = $this->backup->prune();

        app(ActivityLogger::class)->log('pangkas', 'backup', null, ['jumlah' => $dihapus]);

        return back()->with('status', "{$dihapus} cadangan lama dihapus.");
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Ubah nama berkas jadi path absolut, atau null bila tidak sah.
     *
     * Tiga penjagaan sekaligus: pola nama yang ketat, pencocokan dengan
     * daftar berkas yang benar-benar ada, dan pemeriksaan bahwa hasil
     * `realpath` masih berada di dalam folder cadangan. Yang ketiga menangkap
     * symlink yang menunjuk keluar — yang tidak tertangkap dua yang pertama.
     */
    private function resolve(string $nama): ?string
    {
        if (! preg_match('/^dramaverse-[\d_-]+\.tar\.gz$/', $nama)) {
            return null;
        }

        $path = $this->backup->directory().'/'.$nama;

        $nyata = realpath($path);

        $dir = realpath($this->backup->directory());

        if ($nyata === false || $dir === false || ! str_starts_with($nyata, $dir.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $nyata;
    }
}
