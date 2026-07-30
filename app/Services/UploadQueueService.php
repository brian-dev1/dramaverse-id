<?php

namespace App\Services;

use App\Enums\UploadStatus;
use App\Jobs\ProcessEpisodeVideoUpload;
use App\Models\Episode;
use App\Models\EpisodeVideo;
use App\Models\UploadJob;
use App\Models\UploadJobLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Antrean unggah.
 *
 * Kelas ini mengurus SIKLUS HIDUP pekerjaan unggah — menerima berkas,
 * menyimpannya sementara, mengantrekan, memindahkan status, mencatat, dan
 * membersihkan. Kelas ini TIDAK mengunggah apa pun ke storage provider.
 *
 * Pemisahan itu penting dan disengaja. Pengiriman berkas ke provider tetap
 * sepenuhnya milik `EpisodeVideoService`, yang memanggil
 * `StorageEngineInterface` — pusat seluruh operasi berkas sejak Sprint 7.4.
 * Kalau kelas ini ikut menyentuh Storage, akan ada dua jalur upload yang
 * berbeda, dan yang satu pasti tertinggal saat yang lain diperbaiki.
 *
 * Karena itu di berkas ini tidak ada `Storage::`, tidak ada nama disk, dan
 * tidak ada satu pun pengetahuan tentang provider. Yang ada hanya berkas
 * staging di disk server sendiri — yang sifatnya berbeda: ia bukan tujuan
 * penyimpanan, melainkan tempat berkas menunggu antrean.
 *
 * ## Kenapa perlu berkas staging
 *
 * PHP menghapus berkas unggahan sementara begitu request berakhir. Job yang
 * dijalankan worker beberapa detik kemudian akan menemukan berkas yang sudah
 * tidak ada. Jadi berkasnya harus dipindahkan lebih dulu ke tempat yang
 * bertahan, dan itu tidak bisa dihindari oleh rancangan mana pun yang
 * memindahkan unggahan ke background.
 */
class UploadQueueService
{
    /*
    |--------------------------------------------------------------------------
    | Mengantrekan
    |--------------------------------------------------------------------------
    */

    /**
     * Terima berkas video, simpan sementara, lalu antrekan pekerjaannya.
     *
     * Yang dikembalikan adalah barisnya — belum ada berkas yang sampai ke
     * storage provider pada titik ini, dan pemanggil tidak boleh berpura-pura
     * sebaliknya.
     *
     * @param  string  $mode  'auto' | 'manual'
     *
     * @throws RuntimeException bila berkas gagal dipindahkan ke staging
     */
    public function queueEpisodeVideo(
        Episode $episode,
        UploadedFile $file,
        string $mode = 'auto',
        ?int $providerId = null
    ): UploadJob {

        // Semua keterangan berkas dibaca SEBELUM dipindahkan. Setelah
        // `move()`, berkasnya tidak ada lagi di tempat semula dan getSize()
        // maupun getMimeType() akan gagal.
        $originalName = $this->safeName($file->getClientOriginalName());
        $extension    = $this->safeExtension($file->getClientOriginalExtension());
        $mime         = $this->mimeOf($file);
        $size         = (int) $file->getSize();

        $uuid = (string) Str::uuid();

        $staged = $this->stage($file, $uuid, $extension);

        $job = UploadJob::create([
            'uuid'                  => $uuid,
            'type'                  => UploadJob::TYPE_EPISODE_VIDEO,
            'episode_id'            => $episode->id,
            'requested_provider_id' => $mode === 'manual' ? $providerId : null,
            'storage_mode'          => $mode === 'manual' ? 'manual' : 'auto',
            'status'                => UploadStatus::PENDING->value,
            'original_filename'     => $originalName,
            'extension'             => $extension,
            'mime_type'             => $mime,
            'size'                  => $size,
            'staged_path'           => $staged,
            'attempts'              => 0,
            'max_attempts'          => $this->tries(),
            'queue_connection'      => $this->connectionLabel(),
            'queue_name'            => $this->queueName(),
            'created_by'            => Auth::id(),
            'queued_at'             => now(),
        ]);

        $this->log($job, 'info', 'queued', 'Pekerjaan unggah masuk antrean.', [
            'episode_id'   => $episode->id,
            'drama_id'     => $episode->drama_id,
            'mode'         => $job->storage_mode,
            'provider_id'  => $job->requested_provider_id,
            'size'         => $size,
            'staged_path'  => $staged,
            'queue'        => $job->queue_name,
            'connection'   => $job->queue_connection,
        ]);

        $this->dispatchJob($job);

        return $job;
    }

    /**
     * Pekerjaan yang masih berjalan untuk sebuah episode, bila ada.
     *
     * Dipakai untuk menolak antrean kedua pada episode yang sama. Dua
     * pekerjaan yang berjalan bersamaan untuk satu episode akan saling
     * menimpa: `EpisodeVideoService` memakai `updateOrCreate` pada
     * `episode_id` lalu menghapus objek yang digantikannya, sehingga yang
     * selesai belakangan bisa menghapus berkas yang baru saja ditulis yang
     * satunya — dan yang tersisa adalah baris yang menunjuk objek yang sudah
     * tidak ada.
     */
    public function activeFor(Episode $episode): ?UploadJob
    {
        return UploadJob::query()
            ->where('episode_id', $episode->id)
            ->unfinished()
            ->latest('id')
            ->first();
    }

    /**
     * Kirim pekerjaan ke antrean.
     *
     * Dipanggil saat pertama kali diantrekan DAN saat Retry, supaya kedua
     * jalur memakai koneksi dan nama antrean yang sama persis. Kalau Retry
     * mengantre ke tempat lain, pekerjaan yang diulang akan menggantung di
     * antrean yang tidak didengarkan siapa pun.
     */
    protected function dispatchJob(UploadJob $job): void
    {
        $pending = ProcessEpisodeVideoUpload::dispatch($job->id)
            ->onQueue($this->queueName());

        if ($connection = $this->connection()) {
            $pending->onConnection($connection);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Perpindahan status
    |--------------------------------------------------------------------------
    */

    /**
     * Ambil pekerjaan: PENDING -> PROCESSING.
     *
     * Mengembalikan baris yang sudah terkunci dan sudah dipindahkan, atau
     * `null` bila pekerjaannya TIDAK boleh dikerjakan — sudah dibatalkan,
     * sudah selesai, atau sudah diambil worker lain.
     *
     * Transaksi + `lockForUpdate()` di sini bukan kehati-hatian berlebihan.
     * Inilah satu-satunya tempat yang memisahkan "dibatalkan" dari "sedang
     * diproses". Admin menekan Cancel pada milidetik yang sama saat worker
     * mengambil pekerjaannya adalah kejadian yang pasti terjadi cepat atau
     * lambat; tanpa kunci, keduanya bisa membaca status `pending` lalu
     * sama-sama melanjutkan — berkasnya terunggah sekaligus barisnya
     * ditandai dibatalkan.
     *
     * Alasan yang sama dipakai `lockAll()` di Sprint 7.2D untuk menjaga
     * invarian tepat-satu-default.
     */
    public function markProcessing(int $jobId): ?UploadJob
    {
        return DB::transaction(function () use ($jobId) {

            $job = UploadJob::whereKey($jobId)->lockForUpdate()->first();

            if ($job === null || $job->status !== UploadStatus::PENDING) {
                return null;
            }

            $job->forceFill([
                'status'     => UploadStatus::PROCESSING->value,
                'started_at' => now(),
                'attempts'   => $job->attempts + 1,
            ])->save();

            return $job;
        });
    }

    public function markSuccess(UploadJob $job, EpisodeVideo $video, int $durationMs): void
    {
        $job->forceFill([
            'status'           => UploadStatus::SUCCESS->value,
            'episode_video_id' => $video->id,
            'error_class'      => null,
            'error_message'    => null,
            'duration_ms'      => $durationMs,
            'finished_at'      => now(),
        ])->save();

        $this->log($job, 'info', 'success', 'Video tersimpan di storage provider.', [
            'episode_id'  => $job->episode_id,
            'video_id'    => $video->id,
            'provider_id' => $video->storage_provider_id,
            'object_key'  => $video->object_key,
            'size'        => $video->size,
            'durasi_ms'   => $durationMs,
        ]);

        // Berkas staging sudah tidak berguna: salinannya kini ada di provider,
        // dan `episode_videos` sudah tahu di mana. Membiarkannya berarti
        // menyimpan setiap video dua kali, satu di antaranya di disk VPS yang
        // paling cepat penuh.
        $this->releaseStagedFile($job);
    }

    public function markFailed(UploadJob $job, Throwable $e, int $durationMs): void
    {
        $job->forceFill([
            'status'        => UploadStatus::FAILED->value,
            'error_class'   => $e::class,
            'error_message' => Str::limit($e->getMessage(), 2000, ''),
            'duration_ms'   => $durationMs,
            'finished_at'   => now(),
        ])->save();

        $this->log($job, 'error', 'failed', $e->getMessage(), [
            'episode_id' => $job->episode_id,
            'exception'  => $e::class,
            'attempt'    => $job->attempts,
            'durasi_ms'  => $durationMs,

            // Berkas staging SENGAJA tidak dihapus di sini. Ia satu-satunya
            // salinan yang tersisa, dan tanpa itu tombol Retry tidak punya
            // bahan untuk diulang.
            'staging'    => 'dipertahankan untuk Retry',
        ]);
    }

    /**
     * Percobaan gagal, tetapi antrean masih punya jatah percobaan lagi.
     *
     * Statusnya dikembalikan ke PENDING supaya percobaan berikutnya bisa
     * mengambilnya lewat `markProcessing()` — pintu yang sama, dengan kunci
     * yang sama. Tanpa langkah ini, `UPLOAD_QUEUE_TRIES` lebih dari satu tidak
     * akan pernah bekerja: percobaan kedua menemukan baris berstatus FAILED,
     * ditolak, lalu berhenti tanpa jejak.
     *
     * Pesan galatnya tetap disimpan. Kalau percobaan berikutnya juga gagal
     * dengan sebab lain, riwayat log-lah yang menyimpan keduanya.
     */
    public function markRetrying(
        UploadJob $job,
        Throwable $e,
        int $durationMs,
        int $attempt
    ): void {

        $job->forceFill([
            'status'        => UploadStatus::PENDING->value,
            'error_class'   => $e::class,
            'error_message' => Str::limit($e->getMessage(), 2000, ''),
            'duration_ms'   => $durationMs,
            'started_at'    => null,
        ])->save();

        $this->log($job, 'warning', 'retrying', $e->getMessage(), [
            'episode_id'   => $job->episode_id,
            'exception'    => $e::class,
            'percobaan_ke' => $attempt,
            'dari'         => $job->max_attempts,
            'durasi_ms'    => $durationMs,
        ]);
    }

    /**
     * Batalkan pekerjaan yang belum diambil worker.
     *
     * Mengembalikan `false` bila sudah terlambat. Itu bukan kesalahan admin —
     * hanya kabar bahwa pekerjaannya sudah jalan — jadi pemanggil sebaiknya
     * menyampaikannya sebagai keterangan, bukan sebagai galat.
     */
    public function cancel(UploadJob $job): bool
    {
        $ok = DB::transaction(function () use ($job) {

            $fresh = UploadJob::whereKey($job->id)->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->status->canCancel()) {
                return false;
            }

            $fresh->forceFill([
                'status'       => UploadStatus::CANCELLED->value,
                'cancelled_at' => now(),
                'finished_at'  => now(),
            ])->save();

            return true;
        });

        if (! $ok) {
            return false;
        }

        $job->refresh();

        $this->log($job, 'warning', 'cancelled', 'Dibatalkan admin sebelum diproses.', [
            'episode_id' => $job->episode_id,
            'by'         => Auth::id(),
        ]);

        // Pembatalan berarti berkasnya memang tidak dikehendaki. Tidak ada
        // yang akan mengambilnya lagi, dan ukurannya bisa beberapa gigabyte.
        $this->releaseStagedFile($job);

        // Job-nya sendiri tetap ada di tabel `jobs` dan tetap akan diambil
        // worker. Yang menghentikannya adalah `markProcessing()`, yang menolak
        // baris berstatus selain PENDING dan membuat job berhenti tanpa
        // melakukan apa pun. Menghapus payload dari tabel `jobs` di sini
        // memerlukan pengetahuan tentang bentuk payload driver antrean — dan
        // bentuk itu berbeda antara database, redis, dan sqs.
        return true;
    }

    /**
     * Ulangi pekerjaan yang gagal.
     *
     * Statusnya dikembalikan ke PENDING dan pekerjaannya diantrekan lagi.
     * Berkas staging dipakai kembali — tidak ada yang perlu diunggah ulang
     * dari peramban, dan itu justru inti dari menyimpannya.
     */
    public function retry(UploadJob $job): bool
    {
        if (! $job->isRetryable()) {
            return false;
        }

        $ok = DB::transaction(function () use ($job) {

            $fresh = UploadJob::whereKey($job->id)->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->status->canRetry()) {
                return false;
            }

            $fresh->forceFill([
                'status'        => UploadStatus::PENDING->value,
                'error_class'   => null,
                'error_message' => null,
                'duration_ms'   => null,
                'started_at'    => null,
                'finished_at'   => null,
                'queued_at'     => now(),

                // Batas percobaan dinaikkan sebanyak yang diminta admin.
                // Tanpa ini, pekerjaan yang sudah memakai seluruh jatah
                // percobaannya akan langsung dianggap habis pada Retry
                // pertama, dan tombolnya tidak pernah benar-benar bekerja.
                'max_attempts'  => $fresh->attempts + $this->tries(),
            ])->save();

            return true;
        });

        if (! $ok) {
            return false;
        }

        $job->refresh();

        $this->log($job, 'info', 'retried', 'Diulang atas permintaan admin.', [
            'episode_id'  => $job->episode_id,
            'percobaan_ke' => $job->attempts + 1,
            'by'          => Auth::id(),
        ]);

        $this->dispatchJob($job);

        return true;
    }

    /**
     * Hapus baris beserta berkas staging-nya.
     *
     * Hanya untuk pekerjaan yang sudah selesai. Menghapus baris yang masih
     * PENDING akan meninggalkan job di tabel `jobs` yang menunjuk baris yang
     * tidak ada lagi — job itu akan berjalan, tidak menemukan apa-apa, dan
     * gagal dengan pesan yang tidak menjelaskan apa pun.
     */
    public function delete(UploadJob $job): bool
    {
        if (! $job->status->isFinal()) {
            return false;
        }

        $this->releaseStagedFile($job);

        // Log ikut terhapus lewat cascade di migration.
        $job->delete();

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Berkas staging
    |--------------------------------------------------------------------------
    */

    /**
     * Pindahkan berkas unggahan ke folder staging.
     *
     * Mengembalikan path RELATIF terhadap storage_path().
     *
     * Ini satu-satunya tempat di seluruh sprint ini yang menulis berkas
     * langsung, dan itu memang tidak bisa dihindari: `StorageEngine` menulis
     * ke storage provider, sedangkan yang dibutuhkan di sini justru sebaliknya
     * — berkas harus tetap di server sampai worker sempat mengirimnya.
     *
     * @throws RuntimeException
     */
    protected function stage(UploadedFile $file, string $uuid, ?string $extension): string
    {
        $relative = $this->stagingDir();

        $absolute = storage_path($relative);

        if (! is_dir($absolute) && ! @mkdir($absolute, 0775, true) && ! is_dir($absolute)) {
            throw new RuntimeException(
                'Folder staging tidak bisa dibuat: '.$absolute.'. '
                .'Periksa izin tulis pada folder storage/.'
            );
        }

        $filename = $uuid.($extension ? '.'.$extension : '');

        try {
            $file->move($absolute, $filename);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Berkas gagal dipindahkan ke folder staging: '.$e->getMessage(),
                0,
                $e
            );
        }

        return $relative.'/'.$filename;
    }

    /**
     * Hapus berkas staging bila statusnya memang sudah membebaskannya.
     *
     * Berkas yang sudah tidak ada bukan kegagalan — pembersihan harus
     * idempoten, sama seperti `StorageEngine::delete()`.
     */
    public function releaseStagedFile(UploadJob $job): void
    {
        $path = $job->stagedFullPath();

        if ($path === null || ! is_file($path)) {
            return;
        }

        if (@unlink($path)) {
            $job->forceFill(['staged_path' => null])->save();

            return;
        }

        $this->log($job, 'warning', 'orphan', 'Berkas staging gagal dihapus.', [
            'path'    => $path,
            'catatan' => 'Perlu dihapus manual. Statusnya tidak terpengaruh.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pencatatan
    |--------------------------------------------------------------------------
    */

    /**
     * Catat satu peristiwa, ke tabel dan ke berkas log sekaligus.
     *
     * Dua tempat karena keduanya menjawab pertanyaan yang berbeda. Tabel
     * menjawab "apa yang terjadi pada unggahan ini" dan bisa dibuka admin di
     * panel. Berkas log menjawab "apa yang terjadi di server pada jam itu" dan
     * memuat konteks di sekitarnya — termasuk galat yang sama sekali tidak
     * dikenali kode ini.
     *
     * Kegagalan menulis log TIDAK boleh menggagalkan unggahan. Baris log yang
     * hilang mengganggu; unggahan 4 GB yang batal karena baris log gagal
     * ditulis jauh lebih buruk.
     */
    public function log(
        UploadJob $job,
        string $level,
        string $event,
        ?string $message = null,
        array $context = []
    ): void {

        try {
            UploadJobLog::create([
                'upload_job_id' => $job->id,
                'level'         => $level,
                'event'         => $event,
                'message'       => $message === null ? null : Str::limit($message, 2000, ''),
                'context'       => $context ?: null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        try {
            Log::channel(config('storage.engine.log_channel') ?: config('logging.default'))
                ->log($level, 'upload.queue.'.$event, $context + [
                    'upload_job' => $job->uuid,
                    'status'     => $job->status->value,
                    'message'    => $message,
                ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Konfigurasi
    |--------------------------------------------------------------------------
    */

    public function queueName(): string
    {
        return (string) (config('storage.queue.name') ?: 'uploads');
    }

    public function connection(): ?string
    {
        $connection = config('storage.queue.connection');

        return $connection ? (string) $connection : null;
    }

    /** Nama koneksi untuk ditampilkan: yang eksplisit, atau bawaan aplikasi. */
    public function connectionLabel(): string
    {
        return $this->connection() ?: (string) config('queue.default');
    }

    public function timeout(): int
    {
        return max(60, (int) config('storage.queue.timeout', 3600));
    }

    public function tries(): int
    {
        return max(1, (int) config('storage.queue.tries', 1));
    }

    public function stagingDir(): string
    {
        return trim((string) (config('storage.queue.staging_dir') ?: 'app/upload-queue'), '/');
    }

    /**
     * Antrean berjalan sinkron — job dieksekusi di dalam request.
     *
     * Ditampilkan di panel sebagai peringatan. Dengan driver `sync`, seluruh
     * tujuan sprint ini tidak tercapai: unggahan tetap memblokir request, dan
     * status Pending tidak pernah terlihat karena job selesai sebelum
     * responsnya dikirim. Tanpa peringatan, orang akan menyimpulkan bahwa
     * antreannya bekerja sangat cepat.
     */
    public function isSynchronous(): bool
    {
        $connection = $this->connectionLabel();

        return config("queue.connections.{$connection}.driver") === 'sync';
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    protected function safeName(?string $name): string
    {
        $name = basename(str_replace('\\', '/', trim((string) $name)));

        return $name === '' ? 'tanpa-nama' : mb_substr($name, 0, 255);
    }

    protected function safeExtension(?string $extension): ?string
    {
        $extension = preg_replace('/[^a-z0-9]/', '', Str::lower((string) $extension));

        return $extension === '' ? null : mb_substr($extension, 0, 20);
    }

    protected function mimeOf(UploadedFile $file): string
    {
        try {
            $mime = $file->getMimeType();
        } catch (Throwable) {
            $mime = null;
        }

        return $mime ?: ($file->getClientMimeType() ?: 'application/octet-stream');
    }
}
