<?php

namespace App\Support\Concerns;

use App\Models\UploadJob;
use App\Models\User;
use App\Services\UploadQueueService;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

/**
 * Bagian yang sama di kedua job unggah antrean.
 *
 * `ProcessDramaAssetUpload` dan `ProcessEpisodeVideoUpload` menjalankan alur
 * yang berbeda, tetapi dua hal di dalamnya identik: menjalankan pekerjaan
 * atas nama admin yang mengantrekannya, dan melepaskan baris antrean saat
 * job dihentikan dari luar.
 *
 * Disatukan di Phase 12. Sebelumnya keduanya punya salinan sendiri, dan
 * salinan dari penanganan batas waktu adalah hal terakhir yang boleh berbeda
 * antar dua jalur — yang tertinggal akan meninggalkan baris PROCESSING
 * selamanya tanpa ada yang menyadarinya.
 *
 * Kelas yang memakainya wajib punya properti `$uploadJobId`.
 */
trait RunsUploadJob
{
    /**
     * Jalankan sesuatu seolah-olah admin yang mengantrekannya sedang masuk.
     *
     * Worker tidak punya sesi, sehingga `Auth::id()` bernilai null di dalam
     * job — dan dua tempat di jalur unggah membacanya: kolom `uploaded_by`
     * dan `user_id` di `activity_logs`.
     *
     * Penggunanya dilupakan lagi di `finally`. Proses worker berumur panjang
     * dan mengerjakan banyak job berurutan; identitas yang tertinggal akan
     * menempel pada pekerjaan milik admin lain sesudahnya.
     */
    protected function sebagaiPengunggah(?int $userId, callable $tindakan)
    {
        $user = $userId === null ? null : User::find($userId);

        if ($user === null) {
            return $tindakan();
        }

        $guard = Auth::guard();

        $guard->setUser($user);

        try {
            return $tindakan();
        } finally {
            if (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }

    /**
     * Dipanggil antrean ketika seluruh percobaan habis — termasuk saat job
     * dihentikan karena melewati batas waktu.
     *
     * Kasus batas waktu adalah alasan utama method ini ada: prosesnya
     * dimatikan dari luar, sehingga blok `catch` di `handle()` tidak pernah
     * berjalan dan barisnya akan tertinggal berstatus PROCESSING selamanya.
     */
    public function failed(?Throwable $e): void
    {
        $queue = app(UploadQueueService::class);

        $job = UploadJob::find($this->uploadJobId);

        if ($job === null || $job->status->isFinal()) {
            return;
        }

        $queue->markFailed(
            $job,
            $e ?: new RuntimeException(
                'Pekerjaan dihentikan antrean tanpa pesan galat. '
                .'Penyebab yang paling sering: melewati batas waktu '
                .'(UPLOAD_QUEUE_TIMEOUT) atau worker yang direstart di tengah jalan.'
            ),
            (int) ($job->duration_ms ?: 0)
        );
    }
}
