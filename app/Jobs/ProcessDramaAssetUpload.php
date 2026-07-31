<?php

namespace App\Jobs;

use App\Support\Concerns\RunsUploadJob;
use App\Enums\DramaAssetType;
use App\Models\Drama;
use App\Models\UploadJob;
use App\Services\DramaAssetService;
use App\Services\UploadQueueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Kirim satu aset drama dari berkas staging ke storage provider.
 *
 * Pasangan `ProcessEpisodeVideoUpload` untuk jenis berkas kedua. Bentuknya
 * sengaja menyamai berkas itu baris demi baris, karena keduanya memang
 * mengerjakan hal yang sama pada modul yang berbeda:
 *
 *     Job -> DramaAssetService -> StorageEngineInterface -> StorageManager
 *
 * Tidak ada `Storage::` di berkas ini, tidak ada nama disk, dan tidak ada
 * pengetahuan tentang provider selain id yang diminta admin.
 *
 * ## Kenapa bukan satu kelas job untuk keduanya
 *
 * Sempat dicoba, dan hasilnya lebih buruk. Satu job untuk dua jenis berarti
 * satu `handle()` yang bercabang pada `type`, dengan dua service yang
 * di-inject padahal hanya satu yang dipakai tiap kali, dua bentuk hasil yang
 * harus dibedakan sebelum disimpan, dan dua pesan galat yang harus dipilih.
 * Yang benar-benar sama di antara keduanya — perpindahan status, pembacaan
 * ulang di dalam kunci, pementasan berkas, pencatatan, penanganan batas waktu
 * — sudah berada di `UploadQueueService` dan tidak ditulis dua kali di sini.
 *
 * Yang tersisa di kedua job hanyalah pemanggilan service modulnya
 * masing-masing, dan menyatukan itu berarti menambah percabangan demi
 * menghindari pengulangan yang lebih sedikit daripada percabangannya.
 */
class ProcessDramaAssetUpload implements ShouldQueue
{
    use RunsUploadJob;

    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $deleteWhenMissingModels = false;

    public function __construct(public int $uploadJobId)
    {
        $this->tries   = max(1, (int) config('storage.queue.tries', 1));
        $this->timeout = max(60, (int) config('storage.queue.timeout', 3600));
    }

    public function handle(UploadQueueService $queue, DramaAssetService $assets): void
    {
        // PENDING -> PROCESSING di dalam kunci baris. `null` berarti
        // pekerjaannya memang tidak boleh dikerjakan — dibatalkan, sudah
        // selesai, atau barisnya dihapus. Berhenti tanpa melempar, supaya
        // pembatalan yang berjalan sebagaimana mestinya tidak mengisi
        // `failed_jobs` dengan kegagalan palsu.
        $job = $queue->markProcessing($this->uploadJobId);

        if ($job === null) {
            return;
        }

        $mulai = microtime(true);

        try {
            $asset = $this->kirim($job, $queue, $assets);

            $queue->markAssetSuccess($job, $asset, $this->elapsed($mulai));

        } catch (Throwable $e) {

            if ($this->attempts() < $this->tries) {
                $queue->markRetrying($job, $e, $this->elapsed($mulai), $this->attempts());
            } else {
                $queue->markFailed($job, $e, $this->elapsed($mulai));
            }

            // Dilempar ulang supaya antrean mencatatnya di `failed_jobs`.
            // Menelannya di sini membuat job terlihat sukses bagi Laravel.
            throw $e;
        }
    }

    /**
     * Bangun kembali berkas staging lalu serahkan ke DramaAssetService.
     *
     * @throws RuntimeException
     */
    protected function kirim(
        UploadJob $job,
        UploadQueueService $queue,
        DramaAssetService $assets
    ) {
        $drama = Drama::find($job->drama_id);

        if ($drama === null) {
            throw new RuntimeException(
                'Drama tujuan sudah dihapus setelah unggahan diantrekan. '
                .'Berkasnya tidak dikirim ke mana pun.'
            );
        }

        $type = DramaAssetType::tryFrom((string) $job->asset_type);

        if ($type === null) {
            throw new RuntimeException(sprintf(
                'Jenis aset "%s" tidak dikenali lagi. Baris antrean ini dibuat '
                .'oleh versi kode yang berbeda; hapus lalu unggah ulang.',
                (string) $job->asset_type
            ));
        }

        $path = $job->stagedFullPath();

        if ($path === null || ! is_file($path)) {
            throw new RuntimeException(
                'Berkas staging tidak ditemukan di '.($path ?: '(kosong)').'. '
                .'Kemungkinan sudah dibersihkan, atau folder storage/ berpindah. '
                .'Unggah ulang berkasnya.'
            );
        }

        $queue->log($job, 'info', 'started', 'Worker mulai mengirim berkas.', [
            'drama_id'    => $drama->id,
            'asset_type'  => $type->value,
            'mode'        => $job->storage_mode,
            'provider_id' => $job->requested_provider_id,
            'size'        => $job->size,
            'percobaan'   => $this->attempts(),
        ]);

        // Argumen terakhir `true` menandai berkas ini sebagai berkas uji, yang
        // membuat Symfony melewati `is_uploaded_file()`. Pemeriksaan itu hanya
        // benar untuk berkas yang baru tiba lewat HTTP; berkas staging bukan
        // itu, dan tanpa penanda ini `isValid()` menolaknya.
        //
        // Tidak ada pemeriksaan yang hilang karenanya: ekstensi, jenis isi,
        // dan ukuran sudah divalidasi saat berkasnya benar-benar diunggah, dan
        // `DramaAssetService::assertAllowed()` memeriksanya SEKALI LAGI di
        // dalam service — yang justru inti dari menaruh penjagaan di sana.
        $file = new UploadedFile(
            $path,
            $job->original_filename,
            $job->mime_type,
            null,
            true
        );

        return $this->sebagaiPengunggah(
            $job->created_by,
            fn () => $assets->upload($drama, $type, $file, $job->requested_provider_id)
        );
    }



    protected function elapsed(float $mulai): int
    {
        return (int) round((microtime(true) - $mulai) * 1000);
    }
}
