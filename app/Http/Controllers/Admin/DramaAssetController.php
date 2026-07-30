<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DramaAssetType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDramaAssetRequest;
use App\Models\Drama;
use App\Models\DramaAsset;
use App\Models\StorageProvider;
use App\Services\DramaAssetService;
use App\Services\Storage\Contracts\StorageEngineInterface;
use App\Services\Storage\Exceptions\StorageEngineException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Asset Manager sebuah drama.
 *
 * Controller ini TIDAK menyentuh Storage, tidak tahu driver apa pun, dan tidak
 * pernah menyebut nama disk. Yang dilakukannya: validasi lewat FormRequest,
 * panggil DramaAssetService, terjemahkan hasilnya menjadi respons.
 *
 * Unggah dan hapus membalas JSON karena halamannya bekerja tanpa memuat ulang
 * — progress bar memerlukan XHR, dan mengganti satu poster tidak seharusnya
 * membuang keadaan seluruh halaman.
 */
class DramaAssetController extends Controller
{
    public function __construct(
        protected DramaAssetService $service,
        protected StorageEngineInterface $storage,
    ) {
    }

    /**
     * Halaman Asset Manager.
     */
    public function index(int $drama): View
    {
        $model = Drama::findOrFail($drama);

        return view('web.pages.admin.drama-assets', [
            'title'      => 'Aset — '.$model->title,
            'drama'      => $model,
            'types'      => DramaAssetType::ordered(),
            'assets'     => $this->service->grouped($model),
            'providers'  => $this->providerOptions(),
            'autoTarget' => $this->autoTarget(),
        ]);
    }

    /**
     * Terima unggahan satu atau beberapa berkas.
     *
     * Payload selalu `files[]`, termasuk untuk jenis tunggal. Satu bentuk
     * untuk semua jenis berarti satu jalur kode di sisi JavaScript maupun di
     * sini — galeri hanya berbeda pada jumlah yang diizinkan.
     */
    public function store(StoreDramaAssetRequest $request, int $drama): JsonResponse
    {
        $model = Drama::findOrFail($drama);

        $type = $request->assetType();

        $providerId = $request->input('storage_mode') === 'manual'
            ? (int) $request->integer('storage_provider_id')
            : null;

        $files = $request->file('files', []);

        try {
            $hasil = $this->service->uploadMany($model, $type, $files, $providerId);
        } catch (Throwable $e) {

            // uploadMany() sudah menangkap kegagalan per berkas; sampai ke sini
            // berarti ada yang salah di luar itu.
            report($e);

            return response()->json([
                'ok'      => false,
                'message' => 'Unggahan gagal karena kesalahan di server. '
                             .'Rinciannya tercatat di log aplikasi.',
            ], 500);
        }

        $berhasil = $hasil['berhasil'];

        $gagal = $hasil['gagal'];

        // Tidak ada satu pun yang berhasil: ini kegagalan, bukan keberhasilan
        // sebagian. Dibalas 422 supaya sisi peramban memperlakukannya sebagai
        // galat, bukan menampilkan pesan sukses yang kosong.
        if ($berhasil->isEmpty()) {
            return response()->json([
                'ok'      => false,
                'message' => $gagal[0]['pesan'] ?? 'Tidak ada berkas yang berhasil diunggah.',
                'gagal'   => $gagal,
            ], 422);
        }

        return response()->json([
            'ok'      => true,
            'message' => $this->ringkasan($type, $berhasil->count(), count($gagal)),
            'gagal'   => $gagal,
            'data'    => $berhasil->map(fn (DramaAsset $a) => $this->serialize($a))->all(),
        ]);
    }

    /**
     * Hapus satu aset beserta berkasnya.
     */
    public function destroy(int $drama, int $asset): JsonResponse
    {
        $model = DramaAsset::where('drama_id', $drama)->findOrFail($asset);

        try {
            $this->service->delete($model);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok'      => false,
                'message' => 'Aset gagal dihapus. Rinciannya tercatat di log aplikasi.',
            ], 500);
        }

        return response()->json([
            'ok'      => true,
            'id'      => $asset,
            'message' => $model->asset_type->label().' dihapus.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    protected function ringkasan(DramaAssetType $type, int $berhasil, int $gagal): string
    {
        $pesan = $type->allowsMultiple()
            ? sprintf('%d berkas ditambahkan ke %s.', $berhasil, $type->label())
            : sprintf('%s tersimpan.', $type->label());

        if ($gagal > 0) {
            $pesan .= sprintf(' %d berkas gagal — lihat rinciannya di bawah.', $gagal);
        }

        return $pesan;
    }

    /**
     * Bentuk JSON satu aset untuk dirender ulang di panel.
     */
    protected function serialize(DramaAsset $asset): array
    {
        return [
            'id'                => $asset->id,
            'asset_type'        => $asset->asset_type->value,
            'provider'          => $asset->provider?->name,
            'provider_id'       => $asset->storage_provider_id,
            'disk'              => $asset->disk,
            'bucket'            => $asset->bucket,
            'object_key'        => $asset->object_key,
            'directory'         => $asset->directory,
            'original_filename' => $asset->original_filename,
            'stored_filename'   => $asset->stored_filename,
            'extension'         => $asset->extension,
            'mime_type'         => $asset->mime_type,
            'size'              => $asset->size,
            'size_human'        => $asset->size_for_humans,
            'checksum'          => $asset->checksum,
            'checksum_short'    => $asset->checksum_short,
            'public_url'        => $asset->public_url,
            'previewable'       => $asset->isPreviewable(),
            'uploaded_at'       => $asset->uploaded_at?->toDateTimeString(),
        ];
    }

    /**
     * Provider yang boleh dipilih di mode Manual.
     *
     * Syaratnya sama dengan yang ditegakkan `UsableStorageProvider`, jadi tidak
     * ada pilihan yang tampil lalu ditolak validasi.
     *
     * @return array<int, string>
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
