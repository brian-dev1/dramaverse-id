<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StorageCollection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEpisodeVideoRequest;
use App\Models\Drama;
use App\Models\Episode;
use App\Models\StorageProvider;
use App\Services\EpisodeVideoService;
use App\Services\Storage\Contracts\StorageEngineInterface;
use App\Services\Storage\Exceptions\StorageEngineException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Unggah video episode.
 *
 * Controller ini TIDAK menyentuh Storage, tidak tahu driver apa pun, dan tidak
 * pernah menyebut nama disk. Yang dilakukannya hanya tiga hal: memvalidasi
 * masukan (lewat FormRequest), memanggil EpisodeVideoService, dan menerjemahkan
 * hasilnya menjadi respons.
 *
 * Unggahannya berjalan lewat XHR dan membalas JSON, bukan redirect. Itu
 * keperluan progress bar: satu-satunya cara mengetahui kemajuan pengiriman
 * berkas berukuran gigabyte adalah `XMLHttpRequest.upload.onprogress`, dan itu
 * memerlukan respons yang bisa dibaca JavaScript.
 */
class EpisodeVideoController extends Controller
{
    public function __construct(
        protected EpisodeVideoService $service,
        protected StorageEngineInterface $storage,
    ) {
    }

    /**
     * Halaman unggah.
     */
    public function form(Request $request): View
    {
        $collection = StorageCollection::EPISODE;

        return view('web.pages.admin.episode-video', [
            'title'      => 'Unggah Video Episode',
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
                    'Episode %s%s',
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
     * Terima unggahan.
     *
     * Balasan selalu JSON: `ok` beserta ringkasan hasil, atau `message` yang
     * bisa langsung ditampilkan. Kegagalan penyimpanan dikembalikan sebagai
     * 422 dengan pesan yang sudah dirangkai StorageEngineException — pesan itu
     * menyebut sebab DAN langkah berikutnya, jadi tidak perlu diganti.
     */
    public function store(StoreEpisodeVideoRequest $request): JsonResponse
    {
        $episode = Episode::with('drama:id,title,slug')
            ->findOrFail($request->integer('episode_id'));

        // Judul diperbarui lebih dulu supaya nama berkas yang dibangun service
        // memakai data episode yang sudah final.
        if ($request->filled('title') && $request->string('title')->toString() !== (string) $episode->title) {
            $episode->update(['title' => $request->string('title')->toString()]);
        }

        $providerId = $request->input('storage_mode') === 'manual'
            ? (int) $request->integer('storage_provider_id')
            : null;

        try {
            $video = $this->service->upload(
                $episode,
                $request->file('video'),
                $providerId
            );
        } catch (StorageEngineException $e) {

            // Penolakan yang sudah dijelaskan: konfigurasi provider, ekstensi,
            // ukuran, atau penyimpanan menolak permintaannya.
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 422);

        } catch (Throwable $e) {

            // Kegagalan tak terduga. Pesan aslinya TIDAK dikirim ke peramban —
            // isinya bisa memuat path server atau potongan konfigurasi. Yang
            // lengkap sudah tercatat di log oleh service.
            report($e);

            return response()->json([
                'ok'      => false,
                'message' => 'Unggahan gagal karena kesalahan di server. '
                             .'Rinciannya tercatat di log aplikasi.',
            ], 500);
        }

        $video->loadMissing('provider:id,name,slug,driver');

        return response()->json([
            'ok'      => true,
            'message' => sprintf(
                'Video episode %s tersimpan di %s.',
                str_pad((string) $episode->episode_number, 2, '0', STR_PAD_LEFT),
                $video->provider?->name ?? 'storage provider'
            ),
            'data' => [
                'episode_id'        => $video->episode_id,
                'provider'          => $video->provider?->name,
                'provider_id'       => $video->storage_provider_id,
                'disk'              => $video->disk,
                'bucket'            => $video->bucket,
                'object_key'        => $video->object_key,
                'stored_filename'   => $video->stored_filename,
                'original_filename' => $video->original_filename,
                'extension'         => $video->extension,
                'mime_type'         => $video->mime_type,
                'size'              => $video->size,
                'size_human'        => $video->size_for_humans,
                'checksum'          => $video->checksum,
                'uploaded_at'       => $video->uploaded_at?->toDateTimeString(),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Provider yang boleh dipilih di mode Manual.
     *
     * Syaratnya sama dengan yang ditegakkan `UsableStorageProvider`: aktif,
     * lengkap, adapternya terpasang, tanpa nilai contoh, dan sudah lolos Test
     * Connection. Daftar dan validasinya membaca syarat yang sama, jadi tidak
     * ada pilihan yang tampil lalu ditolak.
     *
     * @return array<int, string>  id => label
     */
    protected function providerOptions(): array
    {
        return StorageProvider::query()
            ->active()
            ->byPriority()
            ->get()
            ->filter(fn (StorageProvider $p) => $p->isUsable() && $p->last_test_status === 'ok')
            ->mapWithKeys(fn (StorageProvider $p) => [
                $p->id => sprintf(
                    '%s — %s%s',
                    $p->name,
                    $p->driver->label(),
                    $p->is_default ? ' (default)' : ''
                ),
            ])
            ->all();
    }

    /**
     * Keterangan tujuan mode AUTO, atau null bila belum ada.
     *
     * Ditampilkan di form supaya "Auto" tidak terasa seperti kotak hitam.
     * Admin yang mengunggah berkas 3 GB berhak tahu ke mana berkasnya pergi
     * sebelum menekan tombol.
     */
    protected function autoTarget(): ?string
    {
        try {
            $provider = $this->storage->resolveProvider();
        } catch (StorageEngineException) {
            return null;
        }

        return sprintf('%s — %s', $provider->name, $provider->driver->label());
    }
}
