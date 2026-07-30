<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UploadStatus;
use App\Http\Controllers\Controller;
use App\Models\UploadJob;
use App\Services\Admin\ActivityLogger;
use App\Services\UploadQueueService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Upload Queue.
 *
 * Halaman ini menampilkan RIWAYAT pekerjaan unggah dan tiga tindakan atasnya:
 * Retry, Cancel, dan Hapus. Tidak ada satu pun berkas yang diunggah dari sini
 * — controller ini tidak menyentuh Storage, tidak menyentuh berkas, dan tidak
 * tahu provider apa pun. Semuanya lewat `UploadQueueService`.
 *
 * Yang sengaja TIDAK dibuat: grafik, ringkasan, penghitung, dan pembaruan
 * otomatis lintas halaman. Spesifikasi sprint melarang monitoring, dan
 * batasnya diambil di situ: daftar dan tindakan boleh, pengamatan tidak.
 * Satu-satunya pembaruan otomatis adalah polling status pada baris yang belum
 * selesai, dan itu berhenti sendiri ketika semuanya sudah final.
 */
class UploadQueueController extends Controller
{
    public function __construct(
        protected UploadQueueService $queue,
        protected ActivityLogger $activity,
    ) {
    }

    /**
     * Daftar pekerjaan.
     */
    public function index(Request $request): View
    {
        $keyword = trim((string) $request->query('q', ''));

        $status = (string) $request->query('status', '');

        $jobs = UploadJob::query()
            ->with([
                'episode:id,drama_id,episode_number,title',
                'episode.drama:id,title',
                'requestedProvider:id,name',
                'creator:id,name',
            ])
            ->when(
                in_array($status, UploadStatus::values(), true),
                fn ($q) => $q->status($status)
            )
            ->when($keyword !== '', function ($q) use ($keyword) {
                $q->where(function ($sub) use ($keyword) {
                    $sub->where('original_filename', 'like', "%{$keyword}%")
                        ->orWhere('uuid', 'like', "%{$keyword}%")
                        ->orWhereHas(
                            'episode.drama',
                            fn ($d) => $d->where('title', 'like', "%{$keyword}%")
                        );
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('web.pages.admin.upload-queue', [
            'title'      => 'Upload Queue',
            'jobs'       => $jobs,
            'keyword'    => $keyword,
            'status'     => $status,
            'statuses'   => UploadStatus::options(),

            // Ditampilkan apa adanya di halaman. Worker yang tidak
            // mendengarkan antrean ini adalah penyebab paling sering dari
            // pekerjaan yang menggantung di status Menunggu, dan admin tidak
            // bisa menebaknya dari mana pun selain dari sini.
            'queueName'  => $this->queue->queueName(),
            'connection' => $this->queue->connectionLabel(),
            'sync'       => $this->queue->isSynchronous(),
        ]);
    }

    /**
     * Status satu pekerjaan beserta log-nya, sebagai JSON.
     *
     * Dipakai dua tempat: polling di halaman unggah video (sampai statusnya
     * final) dan pembuka rincian di halaman ini.
     */
    public function show(string $uuid): JsonResponse
    {
        $job = $this->find($uuid);

        return response()->json([
            'ok'   => true,
            'data' => $this->serialize($job),
            'logs' => $job->logs->map(fn ($log) => [
                'level'   => $log->level,
                'class'   => $log->level_class,
                'event'   => $log->event,
                'message' => $log->message,
                'at'      => $log->created_at?->format('d M Y H:i:s'),
            ])->all(),
        ]);
    }

    /**
     * Ulangi pekerjaan yang gagal.
     */
    public function retry(string $uuid): RedirectResponse
    {
        $job = $this->find($uuid);

        if (! $job->isRetryable()) {
            return back()->with('error', $this->alasanTidakBisaDiulang($job));
        }

        if (! $this->queue->retry($job)) {
            return back()->with(
                'error',
                'Pekerjaan itu sudah berubah status sebelum permintaan Anda sampai. '
                .'Muat ulang halaman untuk melihat keadaan terbarunya.'
            );
        }

        $this->activity->log('diubah', 'upload', $job, ['aksi' => 'retry']);

        return back()->with('status', 'Pekerjaan dikembalikan ke antrean.');
    }

    /**
     * Batalkan pekerjaan yang belum diambil worker.
     */
    public function cancel(string $uuid): RedirectResponse
    {
        $job = $this->find($uuid);

        if (! $this->queue->cancel($job)) {
            // Bukan kesalahan admin. Worker kebetulan lebih cepat, dan itu
            // memang bisa terjadi — pesannya menjelaskan, bukan menyalahkan.
            return back()->with(
                'error',
                'Terlambat sedikit: pekerjaan itu sudah diambil worker dan tidak '
                .'bisa dibatalkan lagi. Unggahan yang sedang berjalan dihentikan '
                .'di tengah jalan akan meninggalkan berkas separuh di bucket.'
            );
        }

        $this->activity->log('dihapus', 'upload', $job, ['aksi' => 'cancel']);

        return back()->with('status', 'Unggahan dibatalkan dan berkas sementaranya dihapus.');
    }

    /**
     * Hapus baris riwayat beserta berkas sementaranya.
     */
    public function destroy(string $uuid): RedirectResponse
    {
        $job = $this->find($uuid);

        if (! $this->queue->delete($job)) {
            return back()->with(
                'error',
                'Pekerjaan yang belum selesai tidak bisa dihapus. Batalkan dulu '
                .'kalau memang tidak jadi diunggah.'
            );
        }

        $this->activity->log('dihapus', 'upload', $job, ['aksi' => 'hapus riwayat']);

        return back()->with('status', 'Riwayat unggahan dihapus.');
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    protected function find(string $uuid): UploadJob
    {
        return UploadJob::query()
            ->with(['episode.drama:id,title', 'requestedProvider:id,name', 'creator:id,name', 'logs'])
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * Bentuk JSON satu pekerjaan.
     *
     * Dipakai halaman ini DAN halaman unggah video, jadi bentuknya hanya
     * ditulis sekali. Kalau dua halaman menyusunnya sendiri-sendiri, salah
     * satunya pasti tertinggal saat ada kolom baru.
     */
    public function serialize(UploadJob $job): array
    {
        return [
            'uuid'        => $job->uuid,
            'status'      => $job->status->value,
            'status_text' => $job->status->label(),
            'badge'       => $job->status->badgeClass(),
            'final'       => $job->status->isFinal(),
            'episode'     => $job->target_label,
            'storage'     => $job->target_storage,
            'filename'    => $job->original_filename,
            'size'        => $job->size,
            'size_human'  => $job->size_for_humans,
            'attempts'    => $job->attempts,
            'max_attempts' => $job->max_attempts,
            'duration_ms' => $job->duration_ms,
            'error'       => $job->error_message,
            'can_retry'   => $job->isRetryable(),
            'can_cancel'  => $job->isCancellable(),
            'queued_at'   => $job->queued_at?->format('d M Y H:i'),
            'finished_at' => $job->finished_at?->format('d M Y H:i'),
        ];
    }

    /**
     * Kenapa sebuah pekerjaan tidak bisa diulang.
     *
     * Dijawab terpisah karena dua sebabnya memerlukan tindakan yang berbeda:
     * status yang salah berarti tidak ada yang perlu dilakukan, sedangkan
     * berkas staging yang hilang berarti berkasnya harus diunggah ulang dari
     * komputer admin. Satu pesan "tidak bisa diulang" untuk keduanya akan
     * membuat orang menunggu sesuatu yang tidak akan datang.
     */
    protected function alasanTidakBisaDiulang(UploadJob $job): string
    {
        if (! $job->status->canRetry()) {
            return sprintf(
                'Hanya pekerjaan yang Gagal yang bisa diulang. Yang ini berstatus %s.',
                $job->status->label()
            );
        }

        return 'Berkas sementaranya sudah tidak ada di server, jadi tidak ada yang '
               .'bisa diulang. Unggah ulang berkasnya lewat halaman Unggah Video.';
    }
}
