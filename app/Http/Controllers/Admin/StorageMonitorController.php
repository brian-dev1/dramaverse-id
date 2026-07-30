<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorageProvider;
use App\Services\Admin\ActivityLogger;
use App\Services\Storage\StorageMonitorService;
use App\Services\Storage\StorageTestResult;
use App\Services\StorageProviderService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Storage Monitoring.
 *
 * Halaman ini hanya MENGAMATI. Satu-satunya tindakan yang mengubah sesuatu di
 * luar aplikasi adalah Test Connection, dan itu pun bukan milik controller ini
 * — ia diteruskan ke `StorageProviderService::test()` yang sudah ada sejak
 * Sprint 7.1 dan dipakai juga oleh Storage Manager. Menulis versinya sendiri
 * di sini akan menghasilkan dua definisi "berhasil terhubung" yang cepat atau
 * lambat berbeda.
 *
 * Tidak ada `Storage::` di berkas ini, tidak ada nama disk, dan tidak ada
 * berkas yang ditulis maupun dibaca.
 */
class StorageMonitorController extends Controller
{
    public function __construct(
        protected StorageMonitorService $monitor,
        protected StorageProviderService $providers,
        protected ActivityLogger $activity,
    ) {
    }

    /**
     * Halaman monitoring.
     */
    public function index(): View
    {
        return view('web.pages.admin.storage-monitor', [
            'title' => 'Storage Monitoring',
        ] + $this->monitor->snapshot());
    }

    /**
     * Angka terbaru sebagai JSON.
     *
     * Dipakai tombol Refresh Status. Yang disegarkan hanya angkanya, bukan
     * seluruh halaman — memuat ulang halaman akan membuang posisi gulir dan
     * hasil Test Connection yang sedang dibaca, dan hasil itu justru yang
     * paling sering perlu dibaca berulang kali.
     *
     * Sengaja TIDAK menghubungi provider mana pun. Refresh membaca ulang
     * database; menguji koneksi adalah tombol yang berbeda, dengan biaya dan
     * waktu tunggu yang berbeda pula.
     */
    public function refresh(): JsonResponse
    {
        return response()->json([
            'ok'   => true,
            'data' => $this->monitor->snapshot(),
        ]);
    }

    /**
     * Test Connection satu provider dari halaman monitoring.
     *
     * Hasilnya masuk ke panel `session('detail')` yang menetap, bukan toast
     * yang hilang setelah 4 detik. Pesan galat SDK penyimpanan bisa sepanjang
     * satu paragraf, dan justru di situlah petunjuknya — alasan yang sama
     * dipakai Sprint 7.3 di Storage Manager.
     */
    public function test(int $id): RedirectResponse
    {
        $provider = StorageProvider::query()->find($id);

        if ($provider === null) {
            return back()->with(
                'error',
                'Provider itu sudah tidak ada. Muat ulang halaman untuk melihat '
                .'daftar terbarunya.'
            );
        }

        $result = $this->providers->test($provider);

        $this->activity->log('diubah', 'storage', $provider, [
            'aksi'  => 'test connection',
            'dari'  => 'monitoring',
            'hasil' => $result->success ? 'berhasil' : 'gagal',
        ]);

        return back()->with('detail', [
            'ok'      => $result->success,
            'title'   => sprintf('Test Connection: %s', $provider->name),
            'meta'    => $this->meta($provider, $result),
            'message' => $result->message,
            'hint'    => $result->hint(),
        ]);
    }

    /**
     * Baris keterangan di bawah judul panel hasil.
     */
    protected function meta(StorageProvider $provider, StorageTestResult $result): string
    {
        $bagian = [$provider->driver->label()];

        if ($waktu = $result->durationForHumans()) {
            $bagian[] = 'waktu respons '.$waktu;
        }

        $bagian[] = $result->success
            ? 'tulis, baca, dan hapus berhasil'
            : 'gagal sebelum siklus tulis-baca-hapus selesai';

        return implode(', ', $bagian).'.';
    }
}
