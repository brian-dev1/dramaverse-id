<?php

namespace App\Jobs;

use App\Services\Backup\BackupService;
use App\Services\Monitoring\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menjalankan cadangan di antrean.
 *
 * `mysqldump` pada basis data yang sudah besar memakan menit. Menahannya di
 * dalam request admin berakhir dengan galat gateway sementara prosesnya tetap
 * berjalan di belakang — keadaan paling membingungkan yang bisa terjadi,
 * karena yang menekan tombol menyimpulkan cadangannya gagal padahal berhasil.
 *
 * `tries = 1`: cadangan yang gagal karena `mysqldump` tidak terpasang atau
 * disk penuh akan gagal dengan cara yang sama berapa kali pun diulang, dan
 * setiap percobaan menulis berkas besar ke disk yang mungkin justru sedang
 * penuh.
 */
class RunBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** Selonggar mungkin — dump basis data besar memang lama. */
    public int $timeout = 3600;

    public function handle(BackupService $backup, AlertService $alerts): void
    {
        try {
            $path = $backup->create();

        } catch (Throwable $e) {

            // Kritis, penahan dilewati: cadangan yang gagal diam-diam adalah
            // cadangan yang dikira ada sampai hari ia dibutuhkan.
            $alerts->critical(
                'backup-failed',
                'Cadangan gagal dibuat',
                $e->getMessage(),
                ['job' => self::class]
            );

            return;
        }

        if (config('backup.verify_after_create', true)) {

            $hasil = $backup->verify($path);

            if (! $hasil['ok']) {

                $alerts->critical(
                    'backup-corrupt',
                    'Cadangan baru tidak lolos verifikasi',
                    basename($path).': '.$hasil['pesan']
                        ."\n\nBerkasnya ada, tetapi tidak bisa dipercaya untuk restore.",
                    ['berkas' => basename($path)]
                );

                return;
            }
        }

        $dihapus = $backup->prune();

        Log::info('backup.created', [
            'berkas'    => basename($path),
            'size'      => @filesize($path) ?: 0,
            'dipangkas' => $dihapus,
            'dipicu'    => 'antrean',
        ]);
    }

    public function failed(?Throwable $e): void
    {
        app(AlertService::class)->critical(
            'backup-job-failed',
            'Pekerjaan cadangan berhenti',
            $e?->getMessage() ?: 'Worker berhenti sebelum cadangan selesai.'
        );
    }
}
