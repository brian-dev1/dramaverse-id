<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DramaAssetType;
use App\Enums\StorageCollection;
use App\Enums\UploadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBatchUploadRequest;
use App\Models\Drama;
use App\Models\Episode;
use App\Models\UploadJob;
use App\Services\Storage\StorageChoiceService;
use App\Services\UploadQueueService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Batch Upload.
 *
 * Banyak berkas sekali jalan, seluruhnya lewat antrean, dan kegagalan satu
 * berkas tidak menghentikan yang lain.
 *
 * Controller ini tidak menyentuh Storage, tidak menyebut nama disk, dan tidak
 * memanggil satu pun service unggah secara langsung. Yang dilakukannya hanya
 * memvalidasi, menyerahkan berkas ke `UploadQueueService`, dan menerjemahkan
 * hasilnya. Pengiriman ke provider tetap milik `EpisodeVideoService` dan
 * `DramaAssetService`, yang keduanya memanggil `StorageEngineInterface`.
 *
 * ## Bagaimana "satu gagal, yang lain tetap jalan" dijamin
 *
 * Di dua tempat, karena ada dua tahap yang bisa gagal:
 *
 * 1. **Saat diterima.** Tiap berkas adalah permintaan HTTP tersendiri. Berkas
 *    yang ditolak validasi menghasilkan satu respons 422 untuk berkas itu
 *    saja; peramban mencatatnya sebagai gagal lalu melanjutkan ke berkas
 *    berikutnya.
 *
 * 2. **Saat dikirim ke provider.** Tiap berkas adalah pekerjaan antrean
 *    tersendiri. Job yang gagal ditandai Gagal di barisnya sendiri, dan tidak
 *    ada job lain yang tahu maupun terpengaruh.
 *
 * Yang menghubungkan keduanya adalah `batch_uuid` — satu-satunya alasan
 * kolom itu ada. Tanpa penanda itu, dua puluh baris di Upload Queue tidak
 * punya cara menunjukkan bahwa mereka satu tindakan admin.
 */
class BatchUploadController extends Controller
{
    public function __construct(
        protected UploadQueueService $queue,
        protected StorageChoiceService $choices,
    ) {
    }

    /**
     * Halaman batch upload.
     */
    public function form(): View
    {
        return view('web.pages.admin.batch-upload', [
            'title'      => 'Batch Upload',
            'dramas'     => Drama::orderBy('title')->get(['id', 'title']),
            'providers'  => $this->choices->manualOptions(),
            'autoTarget' => $this->choices->autoTarget(),

            // Jenis aset yang masuk akal untuk batch: gambar dan subtitle.
            // Dibangun dari enum, bukan didaftar ulang — jenis baru di
            // `DramaAssetType` ikut muncul di sini tanpa berkas ini disunting.
            'assetTypes' => $this->assetTypeOptions(),

            'videoExtensions' => StorageCollection::EPISODE->extensions(),

            'queueName'  => $this->queue->queueName(),
            'connection' => $this->queue->connectionLabel(),
            'sync'       => $this->queue->isSynchronous(),
        ]);
    }

    /**
     * Terima SATU berkas dari sebuah batch dan antrekan pengirimannya.
     *
     * Balasannya 202 Accepted: permintaannya diterima, pekerjaannya belum
     * selesai. Belum ada satu byte pun yang sampai ke storage provider ketika
     * respons ini dikirim, dan 200 akan mengatakan sebaliknya.
     */
    public function store(StoreBatchUploadRequest $request): JsonResponse
    {
        $batch = $this->resolveBatch($request->input('batch'));

        $mode = $request->input('storage_mode') === 'manual' ? 'manual' : 'auto';

        $providerId = $mode === 'manual'
            ? (int) $request->integer('storage_provider_id')
            : null;

        $penolakan = $request->kind() === StoreBatchUploadRequest::KIND_ASSET
            ? $this->tolakAsetBentrok($request)
            : $this->tolakVideoBentrok($request);

        if ($penolakan !== null) {
            return response()->json(['ok' => false, 'message' => $penolakan], 422);
        }

        try {
            $job = $request->kind() === StoreBatchUploadRequest::KIND_ASSET
                ? $this->antreAset($request, $mode, $providerId, $batch)
                : $this->antreVideo($request, $mode, $providerId, $batch);

        } catch (Throwable $e) {

            // Yang bisa gagal di sini hanyalah penyimpanan sementara di server
            // — paling sering karena folder storage/ tidak bisa ditulis. Pesan
            // aslinya TIDAK dikirim ke peramban karena bisa memuat path
            // server; yang lengkap tercatat di log.
            report($e);

            return response()->json([
                'ok'      => false,
                'message' => 'Berkas gagal disimpan sementara di server, jadi tidak '
                             .'diantrekan. Rinciannya tercatat di log aplikasi.',
            ], 500);
        }

        return response()->json([
            'ok'    => true,
            'batch' => $batch,
            'data'  => [
                'uuid'       => $job->uuid,
                'status'     => $job->status->value,
                'status_url' => route('admin.upload.show', ['uuid' => $job->uuid]),
                'target'     => $job->target_label,
                'storage'    => $job->target_storage,
                'filename'   => $job->original_filename,
                'size_human' => $job->size_for_humans,
            ],
        ], 202);
    }

    /**
     * Status seluruh pekerjaan dalam satu batch.
     *
     * Satu permintaan untuk dua puluh baris, bukan dua puluh permintaan.
     * Halaman unggah video sudah punya polling per pekerjaan lewat
     * `admin.upload.show`, dan memakainya di sini akan berarti dua puluh
     * permintaan setiap beberapa detik selama batch berjalan.
     */
    public function status(string $batch): JsonResponse
    {
        $jobs = UploadJob::query()->batch($batch)->get();

        return response()->json([
            'ok'   => true,
            'done' => $jobs->every(fn (UploadJob $j) => $j->status->isFinal()),
            'data' => $jobs->map(fn (UploadJob $job) => [
                'uuid'        => $job->uuid,
                'status'      => $job->status->value,
                'status_text' => $job->status->label(),
                'badge'       => $job->status->badgeClass(),
                'final'       => $job->status->isFinal(),
                'filename'    => $job->original_filename,
                'target'      => $job->target_label,
                'error'       => $job->error_message,
            ])->all(),

            'ringkasan' => [
                'total'   => $jobs->count(),
                'sukses'  => $jobs->where('status', UploadStatus::SUCCESS)->count(),
                'gagal'   => $jobs->where('status', UploadStatus::FAILED)->count(),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pengantrean
    |--------------------------------------------------------------------------
    */

    protected function antreVideo(
        StoreBatchUploadRequest $request,
        string $mode,
        ?int $providerId,
        string $batch
    ): UploadJob {

        $episode = Episode::with('drama:id,title,slug')
            ->findOrFail($request->integer('episode_id'));

        return $this->queue->queueEpisodeVideo(
            $episode,
            $request->file('file'),
            $mode,
            $providerId,
            $batch
        );
    }

    protected function antreAset(
        StoreBatchUploadRequest $request,
        string $mode,
        ?int $providerId,
        string $batch
    ): UploadJob {

        $drama = Drama::findOrFail($request->integer('drama_id'));

        return $this->queue->queueDramaAsset(
            $drama,
            $request->assetType(),
            $request->file('file'),
            $mode,
            $providerId,
            $batch
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Penjagaan
    |--------------------------------------------------------------------------
    */

    /**
     * Tolak video kedua untuk episode yang sama.
     *
     * Penjagaan yang sama persis dengan halaman unggah satuan, dan alasannya
     * juga sama: dua pekerjaan yang berjalan bersamaan untuk satu episode
     * saling menimpa baris `episode_videos` dan masing-masing menghapus objek
     * yang digantikannya.
     *
     * Di batch ini justru LEBIH mudah terjadi: dua berkas dalam satu batch
     * yang keliru dipetakan ke episode yang sama akan mengantre berurutan
     * dalam hitungan detik.
     */
    protected function tolakVideoBentrok(StoreBatchUploadRequest $request): ?string
    {
        $episode = Episode::find($request->integer('episode_id'));

        if ($episode === null) {
            return null; // Sudah ditangani validasi `exists`.
        }

        $berjalan = $this->queue->activeFor($episode);

        if ($berjalan === null) {
            return null;
        }

        return sprintf(
            'Episode %s sudah punya unggahan yang %s di antrean (%s). Berkas ini '
            .'tidak diantrekan supaya keduanya tidak saling menimpa.',
            str_pad((string) $episode->episode_number, 2, '0', STR_PAD_LEFT),
            $berjalan->status->label(),
            $berjalan->original_filename
        );
    }

    /**
     * Tolak aset kedua untuk pasangan (drama, jenis) yang hanya boleh satu.
     *
     * Galeri tidak terkena penjagaan ini — banyak berkas memang boleh berjalan
     * bersamaan, dan itulah inti unggah galeri.
     */
    protected function tolakAsetBentrok(StoreBatchUploadRequest $request): ?string
    {
        $drama = Drama::find($request->integer('drama_id'));

        $type = $request->assetType();

        if ($drama === null || $type === null) {
            return null; // Sudah ditangani validasi.
        }

        $berjalan = $this->queue->activeForAsset($drama, $type);

        if ($berjalan === null) {
            return null;
        }

        return sprintf(
            '%s untuk drama ini sudah punya unggahan yang %s di antrean (%s). '
            .'Jenis aset ini hanya boleh punya satu berkas, jadi keduanya akan '
            .'saling menimpa.',
            $type->label(),
            $berjalan->status->label(),
            $berjalan->original_filename
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Penanda batch: yang dikirim peramban, atau yang baru.
     *
     * Nilai dari peramban hanya diterima kalau batch itu memang milik admin
     * yang sedang masuk. Tanpa pemeriksaan itu, siapa pun yang bisa membuka
     * halaman ini bisa menempelkan unggahannya ke batch milik admin lain —
     * bukan kebocoran data, tetapi riwayat yang berbohong tentang siapa
     * melakukan apa.
     */
    protected function resolveBatch(?string $dikirim): string
    {
        if ($dikirim === null || $dikirim === '') {
            return (string) Str::uuid();
        }

        $pemilik = UploadJob::query()
            ->where('batch_uuid', $dikirim)
            ->value('created_by');

        // Batch yang belum punya baris sama sekali tidak bisa dimiliki siapa
        // pun, jadi diterima apa adanya — itu berkas pertama yang gagal lalu
        // diulang peramban.
        if ($pemilik === null || (int) $pemilik === (int) Auth::id()) {
            return $dikirim;
        }

        return (string) Str::uuid();
    }

    /**
     * Jenis aset yang ditawarkan Batch Upload.
     *
     * Dikelompokkan supaya admin melihat lebih dulu mana yang benar-benar
     * menerima banyak berkas. Ini bukan hiasan: memilih "Poster" lalu melempar
     * sepuluh gambar ke sana adalah kekeliruan yang wajar, dan halaman harus
     * mencegahnya sebelum berkasnya dikirim, bukan setelahnya.
     *
     * @return array<string, array{label: string, multiple: bool, max_kb: int, extensions: array<int, string>}>
     */
    protected function assetTypeOptions(): array
    {
        $options = [];

        foreach (DramaAssetType::ordered() as $type) {
            $options[$type->value] = [
                'label'      => $type->label(),
                'multiple'   => $type->allowsMultiple(),
                'max_kb'     => $type->maxKb(),
                'extensions' => $type->extensions(),
            ];
        }

        return $options;
    }
}
