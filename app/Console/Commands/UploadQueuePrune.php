<?php

namespace App\Console\Commands;

use App\Enums\UploadStatus;
use App\Models\UploadJob;
use App\Services\UploadQueueService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Bersihkan sisa antrean unggah.
 *
 * Dua pekerjaan, dan keduanya menyelesaikan masalah yang benar-benar terjadi:
 *
 * 1. **Baris yang tersangkut di PROCESSING.** Worker yang dimatikan di tengah
 *    jalan — restart supervisor, VPS reboot, OOM killer — tidak sempat
 *    menjalankan blok penanganan galat mana pun. Barisnya tertinggal berstatus
 *    "Diproses" selamanya, terlihat seperti unggahan yang masih berjalan
 *    padahal prosesnya sudah tidak ada. Perintah ini menandainya Gagal supaya
 *    tombol Retry bisa dipakai.
 *
 * 2. **Berkas staging yang menumpuk.** Berkas milik pekerjaan yang Gagal
 *    sengaja dipertahankan agar bisa diulang. Kalau tidak pernah ada yang
 *    mengulangnya, berkas berukuran gigabyte itu tinggal di disk VPS tanpa
 *    batas waktu. Setelah beberapa hari, kemungkinan besar memang tidak akan
 *    diulang lagi.
 *
 * Ini BUKAN monitoring. Tidak ada yang diamati, tidak ada yang dilaporkan
 * berkala, dan tidak ada ambang yang memicu peringatan — hanya pembersihan
 * yang dijalankan saat diminta.
 */
class UploadQueuePrune extends Command
{
    protected $signature = 'upload:prune
                            {--days= : Umur berkas staging yang dibersihkan, dalam hari. Bawaannya dari config}
                            {--stuck-only : Hanya lepaskan baris yang tersangkut, jangan hapus berkas}
                            {--dry-run : Tampilkan yang akan dikerjakan tanpa mengubah apa pun}';

    protected $description = 'Lepaskan pekerjaan unggah yang tersangkut dan hapus berkas staging yang sudah lama';

    public function __construct(
        protected UploadQueueService $queue
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->components->warn('Mode dry-run: tidak ada yang diubah.');
        }

        $this->lepaskanYangTersangkut($dry);

        if (! $this->option('stuck-only')) {
            $this->hapusBerkasLama($dry);
        }

        return self::SUCCESS;
    }

    /**
     * Baris PROCESSING yang sudah melewati dua kali batas waktu.
     *
     * Dua kali, bukan satu kali. Pekerjaan yang berjalan tepat di batas waktu
     * masih sah; menandainya gagal saat detik terakhir berarti mematikan
     * unggahan yang justru hampir selesai.
     */
    protected function lepaskanYangTersangkut(bool $dry): void
    {
        $batas = now()->subSeconds($this->queue->timeout() * 2);

        $jobs = UploadJob::status(UploadStatus::PROCESSING)
            ->where('started_at', '<', $batas)
            ->get();

        if ($jobs->isEmpty()) {
            $this->components->info('Tidak ada pekerjaan yang tersangkut.');

            return;
        }

        $this->components->warn(sprintf(
            '%d pekerjaan tersangkut lebih dari %d detik.',
            $jobs->count(),
            $this->queue->timeout() * 2
        ));

        foreach ($jobs as $job) {
            $this->line(sprintf(
                '  %s — %s (mulai %s)',
                $job->uuid,
                $job->original_filename,
                $job->started_at?->diffForHumans() ?: 'entah kapan'
            ));

            if ($dry) {
                continue;
            }

            $this->queue->markFailed(
                $job,
                new RuntimeException(
                    'Pekerjaan tersangkut di status Diproses dan dilepaskan '
                    .'oleh upload:prune. Worker kemungkinan dimatikan di tengah '
                    .'jalan. Berkas staging masih ada, jadi Retry bisa dipakai.'
                ),
                (int) ($job->duration_ms ?: 0)
            );
        }
    }

    /**
     * Berkas staging milik pekerjaan yang sudah selesai dan sudah lama.
     *
     * Yang PENDING dan PROCESSING tidak pernah disentuh, berapa pun umurnya —
     * berkas mereka masih dibutuhkan.
     */
    protected function hapusBerkasLama(bool $dry): void
    {
        $hari = (int) ($this->option('days') ?: config('storage.queue.keep_days', 7));

        $hari = max(1, $hari);

        $jobs = UploadJob::query()
            ->whereNotNull('staged_path')
            ->whereIn('status', [
                UploadStatus::SUCCESS->value,
                UploadStatus::FAILED->value,
                UploadStatus::CANCELLED->value,
            ])
            ->where('updated_at', '<', now()->subDays($hari))
            ->get();

        if ($jobs->isEmpty()) {
            $this->components->info(sprintf(
                'Tidak ada berkas staging yang lebih tua dari %d hari.',
                $hari
            ));

            return;
        }

        $total = 0;

        foreach ($jobs as $job) {
            if (! $job->hasStagedFile()) {
                // Barisnya masih menyebut sebuah path, tetapi berkasnya sudah
                // tidak ada. Kolomnya dikosongkan supaya tombol Retry tidak
                // ditawarkan untuk sesuatu yang pasti gagal.
                if (! $dry) {
                    $job->forceFill(['staged_path' => null])->save();
                }

                continue;
            }

            $ukuran = (int) @filesize($job->stagedFullPath());

            $this->line(sprintf(
                '  %s — %s (%s, %s)',
                $job->uuid,
                $job->original_filename,
                $job->status->label(),
                $job->size_for_humans
            ));

            if ($dry) {
                $total += $ukuran;

                continue;
            }

            $this->queue->releaseStagedFile($job);

            $total += $ukuran;
        }

        $this->components->info(sprintf(
            '%s dibebaskan dari %d pekerjaan.',
            $this->manusiawi($total),
            $jobs->count()
        ));
    }

    protected function manusiawi(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $size = (float) $bytes;
        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return sprintf('%s %s', round($size, $i > 1 ? 2 : 0), $units[$i]);
    }
}
