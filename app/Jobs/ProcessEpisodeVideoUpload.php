<?php

namespace App\Jobs;

use App\Support\Concerns\RunsUploadJob;
use App\Models\Episode;
use App\Models\UploadJob;
use App\Services\EpisodeVideoService;
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
 * Kirim satu video episode dari berkas staging ke storage provider.
 *
 * Job ini TIDAK mengunggah apa pun sendiri. Yang dilakukannya hanya
 * memindahkan status, membangun kembali berkas staging menjadi `UploadedFile`,
 * lalu memanggil `EpisodeVideoService` — modul Sprint 7.5 yang tidak diubah
 * satu baris pun, dan yang di dalamnya memanggil `StorageEngineInterface`
 * dari Sprint 7.4.
 *
 * Rantainya utuh dan tetap satu arah:
 *
 *     Job -> EpisodeVideoService -> StorageEngineInterface -> StorageManager
 *
 * Karena itu tidak ada `Storage::` di berkas ini, tidak ada nama disk, dan
 * tidak ada satu pun pengetahuan tentang provider selain id yang diminta admin
 * dan diteruskan apa adanya.
 *
 * ## Yang dikirim ke antrean hanyalah sebuah id
 *
 * Bukan model, dan bukan berkasnya. Model yang diserialisasi ke payload
 * antrean akan membawa keadaan lama; `SerializesModels` memang memuat ulang
 * dari database, tetapi id saja sudah cukup dan membuat payload tetap kecil.
 * Yang lebih penting: keadaan pekerjaan HARUS dibaca ulang di dalam kunci
 * (lihat `markProcessing`), sehingga apa pun yang dibawa payload tidak boleh
 * dipercaya.
 */
class ProcessEpisodeVideoUpload implements ShouldQueue
{
    use RunsUploadJob;

    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Berapa kali antrean mencoba sendiri sebelum menyerah.
     *
     * Dibaca dari config saat pekerjaan diantrekan, bukan dipatok di kode.
     * Bawaannya satu — pengulangan adalah keputusan admin lewat tombol Retry,
     * bukan sesuatu yang terjadi diam-diam tiga kali untuk berkas 4 GB.
     */
    public int $tries = 1;

    /**
     * Batas waktu satu percobaan, dalam detik.
     *
     * Harus lebih besar dari waktu terlama yang wajar untuk mengirim satu
     * video, dan lebih kecil dari `retry_after` koneksi antrean.
     */
    public int $timeout = 3600;

    /**
     * Job yang gagal tetap disimpan meskipun barisnya sudah hilang.
     *
     * Bawaan Laravel akan membuang job secara diam-diam bila model yang
     * diserialisasi tidak ditemukan. Di sini yang dikirim hanya id, jadi
     * perilaku itu tidak berlaku — ketiadaan baris ditangani eksplisit di
     * `handle()`, dengan pesan yang menyebutkan sebabnya.
     */
    public bool $deleteWhenMissingModels = false;

    public function __construct(public int $uploadJobId)
    {
        $this->tries   = max(1, (int) config('storage.queue.tries', 1));
        $this->timeout = max(60, (int) config('storage.queue.timeout', 3600));
    }

    public function handle(UploadQueueService $queue, EpisodeVideoService $videos): void
    {
        // PENDING -> PROCESSING di dalam kunci baris.
        //
        // `null` berarti pekerjaannya memang tidak boleh dikerjakan: sudah
        // dibatalkan admin, sudah selesai, atau barisnya sudah dihapus. Job
        // berhenti tanpa melakukan apa pun dan TANPA melempar — melempar akan
        // mengisi `failed_jobs` dengan kegagalan palsu untuk pembatalan yang
        // berjalan persis sebagaimana mestinya.
        $job = $queue->markProcessing($this->uploadJobId);

        if ($job === null) {
            return;
        }

        $mulai = microtime(true);

        try {
            $video = $this->kirim($job, $queue, $videos);

            $queue->markSuccess($job, $video, $this->elapsed($mulai));

        } catch (Throwable $e) {

            // Masih ada jatah percobaan otomatis: kembalikan ke PENDING supaya
            // percobaan berikutnya bisa mengambilnya lagi lewat jalur yang
            // sama. Tanpa ini, percobaan kedua akan menemukan baris berstatus
            // FAILED, ditolak `markProcessing()`, lalu berhenti diam-diam —
            // dan `tries` yang lebih dari satu tidak akan pernah bekerja.
            if ($this->attempts() < $this->tries) {
                $queue->markRetrying($job, $e, $this->elapsed($mulai), $this->attempts());
            } else {
                $queue->markFailed($job, $e, $this->elapsed($mulai));
            }

            // Dilempar ulang supaya antrean mencatatnya di `failed_jobs` dan
            // menjalankan mekanisme percobaan ulangnya sendiri. Menelan
            // exception di sini akan membuat job terlihat sukses bagi Laravel.
            throw $e;
        }
    }

    /**
     * Bangun kembali berkas staging lalu serahkan ke EpisodeVideoService.
     *
     * @throws RuntimeException
     */
    protected function kirim(
        UploadJob $job,
        UploadQueueService $queue,
        EpisodeVideoService $videos
    ) {
        $episode = Episode::with('drama:id,title,slug')->find($job->episode_id);

        if ($episode === null) {
            throw new RuntimeException(
                'Part tujuan sudah dihapus setelah unggahan diantrekan. '
                .'Berkasnya tidak dikirim ke mana pun.'
            );
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
            'episode_id'  => $episode->id,
            'drama_id'    => $episode->drama_id,
            'mode'        => $job->storage_mode,
            'provider_id' => $job->requested_provider_id,
            'size'        => $job->size,
            'percobaan'   => $this->attempts(),
        ]);

        // Argumen terakhir `true` menandai berkas ini sebagai berkas uji,
        // yang membuat Symfony melewati `is_uploaded_file()`. Pemeriksaan itu
        // hanya benar untuk berkas yang baru saja tiba lewat HTTP; berkas
        // staging bukan itu, dan tanpa penanda ini `isValid()` akan menolaknya
        // — Storage Engine akan menganggap unggahannya tidak selesai.
        //
        // Penanda ini TIDAK melonggarkan pemeriksaan apa pun yang penting:
        // jenis berkas, ekstensi, dan ukurannya sudah divalidasi
        // `StoreEpisodeVideoRequest` saat berkasnya benar-benar diunggah, dan
        // Storage Engine memeriksa ekstensi terlarang sekali lagi.
        $file = new UploadedFile(
            $path,
            $job->original_filename,
            $job->mime_type,
            null,
            true
        );

        return $this->sebagaiPengunggah(
            $job->created_by,
            fn () => $videos->upload($episode, $file, $job->requested_provider_id)
        );
    }



    protected function elapsed(float $mulai): int
    {
        return (int) round((microtime(true) - $mulai) * 1000);
    }
}
