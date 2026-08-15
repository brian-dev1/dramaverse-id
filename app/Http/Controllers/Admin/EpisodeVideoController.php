<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StorageCollection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEpisodeVideoRequest;
use App\Models\Drama;
use App\Models\Episode;
use App\Services\Storage\StorageChoiceService;
use App\Services\UploadQueueService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Unggah video episode.
 *
 * Controller ini TIDAK menyentuh Storage, tidak tahu driver apa pun, dan tidak
 * pernah menyebut nama disk. Yang dilakukannya hanya tiga hal: memvalidasi
 * masukan (lewat FormRequest), menyerahkan berkasnya ke antrean, dan
 * menerjemahkan hasilnya menjadi respons.
 *
 * ## Sejak Sprint 7.7: berkasnya diantrekan, bukan dikirim
 *
 * `store()` tidak lagi memanggil `EpisodeVideoService` secara langsung.
 * Berkasnya diterima, disimpan sementara di server, lalu pekerjaannya
 * diantrekan — dan responsnya kembali dalam hitungan milidetik alih-alih
 * menahan request selama pengiriman ke bucket berlangsung.
 *
 * Yang TIDAK berubah: `EpisodeVideoService` tetap satu-satunya yang menulis
 * video ke storage, dan ia tetap memanggil `StorageEngineInterface`. Yang
 * berpindah hanyalah SIAPA yang memanggilnya — dulu request, sekarang worker.
 *
 * Perlu ditegaskan karena mudah disalahpahami: pengiriman berkas dari peramban
 * ke server TIDAK bisa dipindahkan ke background. Byte-nya datang lewat
 * request itu sendiri. Yang dipindahkan adalah bagian yang lambat dan mahal —
 * pengiriman dari server ke storage provider — dan itulah yang selama ini
 * membuat request menggantung berpuluh menit.
 *
 * Unggahannya berjalan lewat XHR dan membalas JSON, bukan redirect. Itu
 * keperluan progress bar: satu-satunya cara mengetahui kemajuan pengiriman
 * berkas berukuran gigabyte adalah `XMLHttpRequest.upload.onprogress`, dan itu
 * memerlukan respons yang bisa dibaca JavaScript.
 */
class EpisodeVideoController extends Controller
{
    public function __construct(
        protected UploadQueueService $queue,
        protected StorageChoiceService $choices,
    ) {
    }

    /**
     * Halaman unggah.
     */
    public function form(Request $request): View
    {
        $collection = StorageCollection::EPISODE;

        return view('web.pages.admin.episode-video', [
            'title'      => 'Unggah Video Part',
            'dramas'     => Drama::orderBy('title')->get(['id', 'title']),
            'selected'   => (int) $request->integer('drama_id') ?: null,

            // Hanya provider yang benar-benar siap. Provider nonaktif tidak
            // boleh muncul — spesifikasi sprint menyebutnya, dan alasannya
            // praktis: memilihnya hanya akan ditolak validasi.
            'providers'  => $this->providerOptions(),
            'autoTarget' => $this->autoTarget(),

            // Ditampilkan supaya admin tahu batas sebenarnya sebelum menunggu
            // unggahan gagal di ujung.
            'maxKb'      => (new StoreEpisodeVideoRequest)->effectiveMaxKb(),
            'extensions' => $collection->extensions(),
            'directory'  => $collection->directory(),

            // Keterangan antrean. Ditampilkan supaya "masuk antrean" tidak
            // terasa seperti berkas yang menghilang: admin bisa melihat ke
            // antrean mana pekerjaannya dikirim, dan halaman mana yang
            // menampilkan nasibnya.
            'queueName'  => $this->queue->queueName(),
            'connection' => $this->queue->connectionLabel(),
            'sync'       => $this->queue->isSynchronous(),
        ]);
    }

    /**
     * Daftar episode sebuah drama, untuk mengisi dropdown kedua.
     *
     * Dibuat sebagai endpoint terpisah, bukan dimuat seluruhnya di halaman.
     * Menanam semua episode dari semua drama ke dalam HTML akan membesar
     * seiring katalog bertambah, dan hampir semuanya tidak akan pernah dipakai
     * dalam satu kali kunjungan.
     */
    public function episodes(int $drama): JsonResponse
    {
        $episodes = Episode::where('drama_id', $drama)
            ->with('video:id,episode_id,stored_filename,size,storage_provider_id')
            ->orderBy('episode_number')
            ->get(['id', 'episode_number', 'title']);

        return response()->json([
            'data' => $episodes->map(fn (Episode $e) => [
                'id'     => $e->id,
                'number' => $e->episode_number,
                'title'  => $e->title,
                'label'  => sprintf(
                    'Part %s%s',
                    str_pad((string) $e->episode_number, 2, '0', STR_PAD_LEFT),
                    $e->title ? ' — '.$e->title : ''
                ),

                // Supaya admin diberi tahu bahwa episode itu SUDAH punya video
                // dan mengunggah lagi berarti menggantinya.
                'has_video'  => $e->video !== null,
                'video_name' => $e->video?->stored_filename,
            ])->all(),
        ]);
    }

    /**
     * Terima unggahan dan antrekan pengirimannya.
     *
     * Balasannya **202 Accepted**, bukan 200. Kode itu berarti persis apa yang
     * terjadi: permintaannya diterima, tetapi pekerjaannya belum selesai.
     * Membalas 200 akan mengatakan hal yang tidak benar — belum ada satu byte
     * pun yang sampai ke storage provider ketika respons ini dikirim.
     *
     * Yang dikembalikan adalah `uuid` pekerjaan beserta URL untuk menanyakan
     * statusnya. Peramban menanyakannya berkala sampai statusnya final.
     */
    public function store(StoreEpisodeVideoRequest $request): JsonResponse
    {
        $episode = Episode::with('drama:id,title,slug')
            ->findOrFail($request->integer('episode_id'));

        // Judul diperbarui lebih dulu supaya nama berkas yang dibangun service
        // memakai data episode yang sudah final saat worker mengerjakannya.
        if ($request->filled('title') && $request->string('title')->toString() !== (string) $episode->title) {
            $episode->update(['title' => $request->string('title')->toString()]);
        }

        $mode = $request->input('storage_mode') === 'manual' ? 'manual' : 'auto';

        $providerId = $mode === 'manual'
            ? (int) $request->integer('storage_provider_id')
            : null;

        // Satu episode tidak boleh punya dua pekerjaan yang berjalan
        // bersamaan. Keduanya menulis ke baris `episode_videos` yang sama dan
        // masing-masing menghapus objek yang digantikannya — yang selesai
        // belakangan bisa menghapus berkas yang baru saja ditulis yang
        // satunya, dan yang tersisa adalah baris yang menunjuk objek yang
        // sudah tidak ada.
        if ($berjalan = $this->queue->activeFor($episode)) {
            return response()->json([
                'ok'      => false,
                'message' => sprintf(
                    'Part ini sudah punya unggahan yang %s di antrean (%s). '
                    .'Tunggu sampai selesai, atau batalkan dulu di halaman Upload Queue.',
                    $berjalan->status->label(),
                    $berjalan->original_filename
                ),
            ], 422);
        }

        try {
            $job = $this->queue->queueEpisodeVideo(
                $episode,
                $request->file('video'),
                $mode,
                $providerId
            );
        } catch (Throwable $e) {

            // Hanya satu blok catch di sini, dan itu berubah dari sebelumnya.
            //
            // Sampai Sprint 7.6, `store()` menangkap StorageEngineException
            // secara terpisah karena ia memang memanggil engine. Sekarang
            // tidak lagi: yang dipanggil hanya penyimpanan sementara dan
            // pendaftaran ke antrean, dan engine baru berjalan di worker.
            // Blok catch untuk exception yang tidak mungkin sampai ke sini
            // hanya akan menjadi kode mati yang terlihat seperti penanganan.
            //
            // Kegagalan engine kini muncul di baris antrean sebagai status
            // Gagal beserta pesannya, bukan sebagai respons HTTP.
            //
            // Kegagalan yang mungkin di sini — paling sering: folder storage/ tidak
            // bisa ditulis. Pesan aslinya TIDAK dikirim ke peramban karena
            // bisa memuat path server; yang lengkap tercatat di log.
            report($e);

            return response()->json([
                'ok'      => false,
                'message' => 'Berkas gagal disimpan sementara di server, jadi '
                             .'unggahannya tidak diantrekan. Rinciannya tercatat '
                             .'di log aplikasi.',
            ], 422);
        }

        return response()->json([
            'ok'     => true,
            'queued' => true,
            'message' => sprintf(
                'Video part %s masuk antrean. Pengirimannya ke storage '
                .'provider berjalan di latar belakang — halaman ini boleh ditutup.',
                str_pad((string) $episode->episode_number, 2, '0', STR_PAD_LEFT)
            ),
            'data' => [
                'uuid'       => $job->uuid,
                'status'     => $job->status->value,
                'status_url' => route('admin.upload.show', ['uuid' => $job->uuid]),
                'queue_url'  => route('admin.upload.index'),
                'queue'      => $job->queue_name,
                'connection' => $job->queue_connection,
                'filename'   => $job->original_filename,
                'size'       => $job->size,
                'size_human' => $job->size_for_humans,
                'storage'    => $job->target_storage,
            ],
        ], 202);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Provider yang boleh dipilih di mode Manual.
     *
     * Isinya dipindahkan ke `StorageChoiceService` di Sprint 7.9, saat halaman
     * unggah kedua (Batch Upload) memerlukan daftar yang sama. Dua salinan
     * syarat "provider yang boleh dipilih" adalah cara paling mudah
     * mendapatkan satu halaman yang menawarkan provider yang halaman lain
     * tolak. Syarat dan bentuk labelnya tidak berubah sedikit pun.
     *
     * @return array<int, string>  id => label
     */
    protected function providerOptions(): array
    {
        return $this->choices->manualOptions();
    }

    /**
     * Keterangan tujuan mode AUTO, atau null bila belum ada.
     */
    protected function autoTarget(): ?string
    {
        return $this->choices->autoTarget();
    }
}
