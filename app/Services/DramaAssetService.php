<?php

namespace App\Services;

use App\Enums\DramaAssetType;
use App\Models\Drama;
use App\Models\DramaAsset;
use App\Models\StorageProvider;
use App\Services\Admin\ActivityLogger;
use App\Services\Storage\Contracts\StorageEngineInterface;
use App\Services\Storage\Exceptions\StorageEngineException;
use App\Services\Storage\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Aturan bisnis aset drama.
 *
 * Tidak menyentuh Storage, tidak tahu driver apa pun, tidak pernah menyebut
 * nama disk. Satu-satunya jalan ke penyimpanan adalah StorageEngineInterface.
 *
 * Kelas ini menjawab hal-hal yang bukan urusan engine: penamaan berkas yang
 * bermakna, checksum, penyimpanan metadata, aturan tunggal-vs-ganda per jenis
 * aset, penggantian berkas lama, dan pembatalan yang bersih ketika gagal.
 *
 * Catatan tentang duplikasi: alur di sini serupa dengan
 * `EpisodeVideoService` — keduanya menghitung checksum, memanggil engine,
 * menyimpan metadata, dan membersihkan bila gagal. Keduanya TIDAK disatukan
 * di sprint ini karena menyatukannya berarti mengubah modul upload video
 * episode, yang secara tegas dilarang spesifikasi 7.6. Yang benar-benar
 * berbagi kode — pengiriman berkas itu sendiri — sudah berada di Storage
 * Engine, dan tidak ditulis ulang di sini maupun di sana.
 */
class DramaAssetService
{
    public function __construct(
        protected StorageEngineInterface $storage,
        protected ActivityLogger $activity,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Unggah
    |--------------------------------------------------------------------------
    */

    /**
     * Unggah satu aset.
     *
     * Untuk jenis tunggal (semua kecuali galeri), berkas lama otomatis diganti
     * dan objeknya dihapus dari penyimpanan setelah metadata baru tersimpan.
     * Untuk galeri, setiap unggahan menambah baris baru.
     *
     * @param  int|null  $providerId  null = mode AUTO (provider default)
     *
     * @throws StorageEngineException
     */
    public function upload(
        Drama $drama,
        DramaAssetType $type,
        UploadedFile $file,
        ?int $providerId = null
    ): DramaAsset {

        $mode = $providerId === null ? 'auto' : 'manual';

        // Dihitung dari berkas SEMENTARA, sebelum apa pun dikirim. Setelah
        // engine memindahkannya, berkas sementara sudah tidak ada di tempatnya.
        $checksum = $this->checksum($file);

        $lama = $type->allowsMultiple()
            ? null
            : DramaAsset::forDrama($drama->id)->ofType($type)->first();

        $mulai = microtime(true);

        $this->log('info', 'started', [
            'drama_id'      => $drama->id,
            'asset_type'    => $type->value,
            'mode'          => $mode,
            'provider_hint' => $providerId,
            'original_name' => $file->getClientOriginalName(),
            'size'          => (int) $file->getSize(),
            'checksum'      => $checksum,
        ]);

        try {
            // uploadTo(), bukan upload(): direktori dan visibility berasal dari
            // DramaAssetType, bukan dari StorageCollection milik engine.
            // Menambah case ke StorageCollection berarti mengubah Storage
            // Engine, yang dilarang di sprint ini.
            //
            // Pembatasan ekstensi dan ukuran tetap ditegakkan — di FormRequest
            // dan di assertAllowed() di bawah, keduanya membaca aturan dari
            // enum yang sama.
            $stored = $this->storage->uploadTo(
                $file,
                $type->directoryFor($drama->id),
                $providerId,
                [
                    'filename'   => $this->buildFilename($drama, $type, $file),
                    'visibility' => $type->visibility(),
                ]
            );
        } catch (StorageEngineException $e) {
            $this->log('error', 'failed', [
                'drama_id'   => $drama->id,
                'asset_type' => $type->value,
                'mode'       => $mode,
                'exception'  => $e::class,
                'message'    => $e->getMessage(),
                'durasi_ms'  => $this->elapsed($mulai),
            ]);

            throw $e;
        }

        // Objek sudah ada di penyimpanan. Kegagalan apa pun dari titik ini
        // harus MEMBERSIHKANNYA — kalau tidak, bucket menyimpan berkas yang
        // tidak dikenali baris mana pun.
        try {
            $asset = $this->simpanMetadata($drama, $type, $stored, $checksum, $lama);
        } catch (Throwable $e) {
            $this->batalkan($stored, $drama, $type, $e, $mulai, $mode);

            throw $e;
        }

        // Berkas lama dihapus SETELAH metadata baru tersimpan. Urutan
        // sebaliknya meninggalkan drama tanpa aset sama sekali bila unggahan
        // baru gagal di tengah jalan.
        if ($lama !== null) {
            $this->hapusObjek($lama, $asset);
        }

        $this->log('info', $lama === null ? 'success' : 'replaced', [
            'drama_id'      => $drama->id,
            'asset_id'      => $asset->id,
            'asset_type'    => $type->value,
            'mode'          => $mode,
            'provider_id'   => $stored->providerId,
            'provider_name' => $stored->providerName,
            'driver'        => $stored->driver->value,
            'bucket'        => $stored->bucket,
            'object_key'    => $stored->objectKey,
            'size'          => $stored->size,
            'checksum'      => $checksum,
            'durasi_ms'     => $this->elapsed($mulai),
        ]);

        $this->activity->log($lama === null ? 'dibuat' : 'diubah', 'drama', $drama, [
            'aset'     => $type->label(),
            'berkas'   => $stored->objectKey,
            'provider' => $stored->providerName,
            'mode'     => $mode,
        ]);

        return $asset;
    }

    /**
     * Unggah beberapa berkas galeri sekaligus.
     *
     * Kegagalan satu berkas TIDAK membatalkan yang lain. Mengunggah sepuluh
     * gambar lalu kehilangan sembilan yang berhasil karena satu yang rusak
     * adalah perilaku yang menyakitkan dan tidak perlu — hasil per berkas
     * dikembalikan apa adanya supaya panel bisa melaporkan mana yang gagal.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array{berhasil: Collection<int, DramaAsset>, gagal: array<int, array{nama: string, pesan: string}>}
     */
    public function uploadMany(
        Drama $drama,
        DramaAssetType $type,
        array $files,
        ?int $providerId = null
    ): array {

        $berhasil = collect();

        $gagal = [];

        foreach ($files as $file) {
            try {
                $berhasil->push($this->upload($drama, $type, $file, $providerId));
            } catch (Throwable $e) {
                $gagal[] = [
                    'nama'  => $file->getClientOriginalName(),
                    'pesan' => $e instanceof StorageEngineException
                        ? $e->getMessage()
                        : 'Gagal diunggah karena kesalahan di server.',
                ];

                if (! $e instanceof StorageEngineException) {
                    report($e);
                }
            }
        }

        return ['berhasil' => $berhasil, 'gagal' => $gagal];
    }

    /*
    |--------------------------------------------------------------------------
    | Hapus
    |--------------------------------------------------------------------------
    */

    /**
     * Hapus aset beserta berkasnya di penyimpanan.
     *
     * Barisnya dihapus meskipun penghapusan berkas gagal. Alasannya: baris
     * yang tertinggal akan terus menampilkan aset yang admin kira sudah
     * dihapus, dan itu lebih membingungkan daripada satu berkas yatim di
     * bucket — yang setidaknya tercatat di log dan bisa dibersihkan.
     */
    public function delete(DramaAsset $asset): void
    {
        $mulai = microtime(true);

        $bersih = true;

        try {
            $this->storage->delete($asset->storage_provider_id, $asset->object_key);
        } catch (Throwable $e) {
            $bersih = false;

            $this->log('warning', 'orphan', [
                'drama_id'    => $asset->drama_id,
                'asset_type'  => $asset->asset_type->value,
                'provider_id' => $asset->storage_provider_id,
                'object_key'  => $asset->object_key,
                'exception'   => $e::class,
                'message'     => $e->getMessage(),
                'catatan'     => 'Berkas tertinggal di penyimpanan dan perlu '
                                 .'dibersihkan manual. Barisnya tetap dihapus.',
            ]);
        }

        $konteks = [
            'drama_id'    => $asset->drama_id,
            'asset_id'    => $asset->id,
            'asset_type'  => $asset->asset_type->value,
            'provider_id' => $asset->storage_provider_id,
            'object_key'  => $asset->object_key,
            'size'        => $asset->size,
            'durasi_ms'   => $this->elapsed($mulai),
            'berkas'      => $bersih ? 'terhapus' : 'GAGAL dihapus',
        ];

        $drama = $asset->drama;

        $type = $asset->asset_type;

        $asset->delete();

        $this->log('info', 'deleted', $konteks);

        if ($drama !== null) {
            $this->activity->log('dihapus', 'drama', $drama, [
                'aset' => $type->label(),
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Baca
    |--------------------------------------------------------------------------
    */

    /**
     * Aset sebuah drama, dikelompokkan menurut jenisnya.
     *
     * @return array<string, Collection<int, DramaAsset>>
     */
    public function grouped(Drama $drama): array
    {
        $assets = DramaAsset::forDrama($drama->id)
            ->with('provider:id,name,slug,driver,status,deleted_at')
            ->ordered()
            ->get()
            ->groupBy(fn (DramaAsset $a) => $a->asset_type->value);

        $hasil = [];

        foreach (DramaAssetType::ordered() as $type) {
            $hasil[$type->value] = $assets->get($type->value, collect());
        }

        return $hasil;
    }

    /*
    |--------------------------------------------------------------------------
    | Bagian-bagiannya
    |--------------------------------------------------------------------------
    */

    /**
     * Pemeriksaan terakhir sebelum berkas dikirim.
     *
     * FormRequest sudah memeriksa hal yang sama, dan itu memang disengaja:
     * service ini juga dipakai dari luar HTTP nanti (API, perintah artisan,
     * Telegram di sprint berikutnya), dan penjagaan yang hanya ada di
     * FormRequest tidak berlaku di sana.
     *
     * @throws StorageEngineException
     */
    public function assertAllowed(DramaAssetType $type, UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw StorageEngineException::invalidUpload(
                $file->getErrorMessage() ?: 'pengunggahan tidak selesai'
            );
        }

        $extension = Str::lower($file->getClientOriginalExtension());

        if (! in_array($extension, $type->extensions(), true)) {
            throw StorageEngineException::invalidUpload(sprintf(
                'jenis berkas .%s tidak diterima untuk %s. Yang diizinkan: %s',
                $extension ?: '(tanpa ekstensi)',
                $type->label(),
                implode(', ', $type->extensions())
            ));
        }

        $sizeKb = (int) ceil(((int) $file->getSize()) / 1024);

        if ($sizeKb > $type->maxKb()) {
            throw StorageEngineException::invalidUpload(sprintf(
                'berkas berukuran %s KB melewati batas untuk %s, yaitu %s KB',
                number_format($sizeKb),
                $type->label(),
                number_format($type->maxKb())
            ));
        }
    }

    /**
     * SHA256 dari berkas sementara.
     *
     * `hash_file` membaca bertahap, jadi berkas besar tidak masuk memori
     * sekaligus.
     *
     * @throws StorageEngineException
     */
    protected function checksum(UploadedFile $file): string
    {
        $hash = @hash_file('sha256', $file->getRealPath());

        if ($hash === false || $hash === null) {
            throw StorageEngineException::invalidUpload(
                'checksum tidak bisa dihitung, berkas sementaranya tidak terbaca'
            );
        }

        return $hash;
    }

    /**
     * Nama tersimpan yang bermakna bagi manusia.
     *
     * Bentuknya: `slugdrama_poster_a1b2c3d4e5f6.webp`
     *
     * Bagian acak diperlukan: tanpa itu, mengganti poster menghasilkan object
     * key yang sama, dan CDN maupun peramban akan tetap menyajikan gambar
     * lama — gejala klasik "sudah saya ganti tapi yang muncul masih yang lama".
     */
    protected function buildFilename(
        Drama $drama,
        DramaAssetType $type,
        UploadedFile $file
    ): string {

        $slug = Str::slug((string) ($drama->slug ?: $drama->title ?: 'drama'));

        $slug = Str::limit($slug, 60, '') ?: 'drama';

        $extension = Str::lower($file->getClientOriginalExtension() ?: 'bin');

        return sprintf(
            '%s_%s_%s.%s',
            $slug,
            $type->value,
            Str::lower(Str::random(12)),
            $extension
        );
    }

    /**
     * Simpan metadata.
     *
     * Jenis tunggal memakai `updateOrCreate` pada (drama_id, asset_type),
     * sehingga mengunggah lagi mengganti barisnya. Galeri selalu membuat baris
     * baru, dengan `sort_order` melanjutkan yang sudah ada.
     */
    protected function simpanMetadata(
        Drama $drama,
        DramaAssetType $type,
        StoredFile $stored,
        string $checksum,
        ?DramaAsset $lama
    ): DramaAsset {

        return DB::transaction(function () use ($drama, $type, $stored, $checksum, $lama) {

            $data = [
                'storage_provider_id' => $stored->providerId,
                'uploaded_by'         => Auth::id(),
                'disk'                => $this->diskName($stored),
                'bucket'              => $stored->bucket,
                'object_key'          => $stored->objectKey,
                'directory'           => $stored->directory(),
                'original_filename'   => $stored->originalName,
                'stored_filename'     => $stored->fileName,
                'extension'           => $stored->extension,
                'mime_type'           => $stored->mimeType,
                'size'                => $stored->size,
                'checksum'            => $checksum,
                'public_url'          => $stored->url,
                'uploaded_at'         => now(),
            ];

            if ($type->allowsMultiple()) {
                $data['drama_id']   = $drama->id;
                $data['asset_type'] = $type->value;
                $data['sort_order'] = $this->nextSortOrder($drama, $type);

                return DramaAsset::create($data);
            }

            $data['sort_order'] = 0;

            return DramaAsset::updateOrCreate(
                ['drama_id' => $drama->id, 'asset_type' => $type->value],
                $data
            );
        });
    }

    protected function nextSortOrder(Drama $drama, DramaAssetType $type): int
    {
        $tertinggi = (int) DramaAsset::forDrama($drama->id)
            ->ofType($type)
            ->max('sort_order');

        // Kolomnya unsignedSmallInteger: berhenti di 65535 alih-alih
        // menghasilkan nilai yang dipotong diam-diam oleh MySQL.
        return min($tertinggi + 1, 65535);
    }

    /**
     * Identitas disk saat diunggah: slug provider.
     *
     * Dibaca langsung dari model, BUKAN lewat `resolveProvider()`. Method itu
     * memvalidasi ulang dan bisa melempar — dan bila itu terjadi di sini,
     * unggahan yang sudah berhasil akan dibatalkan beserta objeknya dihapus,
     * hanya karena satu kolom keterangan gagal diisi.
     */
    protected function diskName(StoredFile $stored): string
    {
        $slug = StorageProvider::withTrashed()
            ->whereKey($stored->providerId)
            ->value('slug');

        return (string) ($slug ?: $stored->driver->value);
    }

    /**
     * Objek sudah terunggah tetapi metadatanya gagal disimpan.
     */
    protected function batalkan(
        StoredFile $stored,
        Drama $drama,
        DramaAssetType $type,
        Throwable $penyebab,
        float $mulai,
        string $mode
    ): void {

        $bersih = true;

        try {
            $this->storage->delete($stored->providerId, $stored->objectKey);
        } catch (Throwable) {
            $bersih = false;
        }

        $this->log('error', 'failed', [
            'drama_id'    => $drama->id,
            'asset_type'  => $type->value,
            'mode'        => $mode,
            'provider_id' => $stored->providerId,
            'object_key'  => $stored->objectKey,
            'size'        => $stored->size,
            'durasi_ms'   => $this->elapsed($mulai),
            'exception'   => $penyebab::class,
            'message'     => $penyebab->getMessage(),
            'tahap'       => 'penyimpanan metadata',
            'objek'       => $bersih
                ? 'sudah dihapus, tidak ada sisa'
                : 'GAGAL dihapus, perlu dibersihkan manual',
        ]);
    }

    /**
     * Hapus berkas lama setelah penggantian berhasil.
     */
    protected function hapusObjek(DramaAsset $lama, DramaAsset $baru): void
    {
        // Baris lama dan baru adalah baris yang SAMA (updateOrCreate), jadi
        // yang dibandingkan harus nilai object key-nya, bukan id barisnya.
        if ($lama->object_key === $baru->object_key
            && (int) $lama->storage_provider_id === (int) $baru->storage_provider_id) {
            return;
        }

        try {
            $this->storage->delete($lama->storage_provider_id, $lama->object_key);
        } catch (Throwable $e) {
            $this->log('warning', 'orphan', [
                'drama_id'    => $lama->drama_id,
                'provider_id' => $lama->storage_provider_id,
                'object_key'  => $lama->object_key,
                'exception'   => $e::class,
                'message'     => $e->getMessage(),
                'catatan'     => 'Berkas lama tertinggal di penyimpanan dan perlu '
                                 .'dibersihkan manual. Penggantian tetap berhasil.',
            ]);
        }
    }

    protected function elapsed(float $mulai): float
    {
        return round((microtime(true) - $mulai) * 1000);
    }

    /**
     * Catatan khusus alur aset drama.
     *
     * Bukan pengulangan pencatatan Storage Engine: engine mencatat peristiwa
     * pada tingkat berkas tanpa tahu berkas itu milik drama mana. Yang
     * ditambahkan di sini adalah konteks drama, jenis aset, mode, checksum,
     * durasi, dan tahap — termasuk `started`, yang tidak dimiliki engine dan
     * justru satu-satunya yang tercatat bila unggahan mati di tengah jalan.
     */
    protected function log(string $level, string $event, array $context): void
    {
        Log::channel(config('storage.engine.log_channel') ?: config('logging.default'))
            ->log($level, 'drama.asset.'.$event, $context);
    }
}
