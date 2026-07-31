<?php

namespace App\Services;

use App\Support\Concerns\ComputesFileChecksum;
use App\Enums\StorageCollection;
use App\Models\Episode;
use App\Models\EpisodeVideo;
use App\Models\StorageProvider;
use App\Services\Admin\ActivityLogger;
use App\Services\Storage\Contracts\StorageEngineInterface;
use App\Services\Storage\Exceptions\StorageEngineException;
use App\Services\Storage\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Aturan bisnis unggah video episode.
 *
 * Tidak menyentuh Storage, tidak menyentuh disk, tidak tahu driver apa pun.
 * Satu-satunya jalan ke penyimpanan adalah StorageEngineInterface — sehingga
 * memindahkan video dari R2 ke Wasabi tidak mengubah satu baris pun di sini.
 *
 * Yang menjadi tanggung jawab kelas ini dan bukan tanggung jawab engine:
 * penamaan berkas yang bermakna bagi manusia, checksum, penyimpanan metadata,
 * penggantian video lama, dan pembatalan yang bersih ketika ada yang gagal.
 */
class EpisodeVideoService
{
    use ComputesFileChecksum;

    public function __construct(
        protected StorageEngineInterface $storage,
        protected ActivityLogger $activity,
    ) {
    }

    /**
     * Unggah (atau ganti) video sebuah episode.
     *
     * @param  int|null  $providerId  null = mode AUTO (provider default)
     *
     * @throws StorageEngineException
     */
    public function upload(
        Episode $episode,
        UploadedFile $file,
        ?int $providerId = null
    ): EpisodeVideo {

        $mode = $providerId === null ? 'auto' : 'manual';

        // Checksum dihitung dari berkas SEMENTARA, sebelum apa pun dikirim.
        //
        // Urutan ini penting. Setelah engine memindahkan berkasnya, berkas
        // sementara sudah tidak ada di tempatnya. Dan membaca ulang dari
        // penyimpanan untuk menghitung checksum berarti mengunduh kembali
        // berkas berukuran gigabyte — sekaligus mengukur hal yang salah:
        // yang ingin dicatat adalah apa yang dimaksud pengunggah, bukan apa
        // yang kebetulan ada di bucket sesudahnya.
        $checksum = $this->checksum($file);

        $filename = $this->buildFilename($episode, $file);

        $sebelumnya = $episode->video;

        $mulai = microtime(true);

        $this->log('info', 'started', [
            'episode_id'    => $episode->id,
            'drama_id'      => $episode->drama_id,
            'mode'          => $mode,
            'provider_hint' => $providerId,
            'original_name' => $file->getClientOriginalName(),
            'size'          => (int) $file->getSize(),
            'checksum'      => $checksum,
        ]);

        try {
            // Koleksi EPISODE yang menentukan direktori, visibility (private —
            // video berbayar), ekstensi yang diterima, dan batas ukurannya.
            // Tidak ada satu pun nilai itu ditulis ulang di sini.
            $stored = $this->storage->upload(
                $file,
                StorageCollection::EPISODE,
                $providerId,
                ['filename' => $filename]
            );
        } catch (StorageEngineException $e) {
            $this->log('error', 'failed', [
                'episode_id' => $episode->id,
                'mode'       => $mode,
                'provider'   => $providerId,
                'exception'  => $e::class,
                'message'    => $e->getMessage(),
                'durasi_ms'  => $this->elapsed($mulai),
            ]);

            throw $e;
        }

        // Objek sudah ada di penyimpanan. Dari titik ini, kegagalan apa pun
        // harus MEMBERSIHKAN objek itu — kalau tidak, bucket menyimpan berkas
        // yang tidak dikenali baris mana pun di database, dan tidak ada yang
        // akan pernah menemukannya lagi.
        try {
            $video = $this->simpanMetadata($episode, $stored, $checksum);
        } catch (Throwable $e) {
            $this->batalkan($stored, $episode, $e, $mulai, $mode);

            throw $e;
        }

        // Video lama dihapus SETELAH metadata baru tersimpan. Urutan
        // sebaliknya akan meninggalkan episode tanpa video sama sekali bila
        // unggahan yang baru gagal di tengah jalan.
        if ($sebelumnya !== null) {
            $this->hapusObjekLama($sebelumnya, $video);
        }

        $this->log('info', 'success', [
            'episode_id'    => $episode->id,
            'video_id'      => $video->id,
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

        $this->activity->log('diubah', 'episode', $episode, [
            'video'    => $stored->objectKey,
            'provider' => $stored->providerName,
            'mode'     => $mode,
        ]);

        return $video;
    }

    /*
    |--------------------------------------------------------------------------
    | Bagian-bagiannya
    |--------------------------------------------------------------------------
    */


    /**
     * Nama tersimpan yang bermakna bagi manusia.
     *
     * Bentuknya: `slugdrama_episode_07_a1b2c3d4e5f6.mp4`
     *
     * Nama asli dari peramban TIDAK dipakai. Selain rawan (path, karakter
     * yang tidak sah, ekstensi ganda), nama asli video biasanya tidak berguna
     * — "video final REVISI 3.mp4" tidak membantu siapa pun yang membuka
     * bucket dan mencoba menebak isinya. Nama aslinya tetap disimpan di kolom
     * `original_filename`.
     *
     * Bagian acak di ujung diperlukan: tanpa itu, mengunggah ulang episode
     * yang sama menghasilkan key yang sama, dan CDN akan tetap menyajikan
     * berkas lama.
     */
    protected function buildFilename(Episode $episode, UploadedFile $file): string
    {
        $dramaSlug = Str::slug((string) ($episode->drama?->slug ?: $episode->drama?->title ?: 'drama'));

        $dramaSlug = Str::limit($dramaSlug, 60, '') ?: 'drama';

        $nomor = str_pad((string) (int) $episode->episode_number, 2, '0', STR_PAD_LEFT);

        $extension = Str::lower($file->getClientOriginalExtension() ?: 'mp4');

        return sprintf(
            '%s_episode_%s_%s.%s',
            $dramaSlug,
            $nomor,
            Str::lower(Str::random(12)),
            $extension
        );
    }

    /**
     * Simpan metadata dan sambungkan ke episode.
     *
     * `updateOrCreate` pada `episode_id`: mengunggah lagi MENGGANTI baris
     * yang ada, bukan menambah baris kedua. Kolom `episode_id` unik di
     * database, jadi baris kedua memang akan ditolak — lebih baik ditangani
     * di sini daripada muncul sebagai galat integritas.
     *
     * `episodes.video_url` ikut diperbarui supaya pemutar yang sudah ada
     * tetap berfungsi tanpa perubahan. Untuk video privat nilainya `null`,
     * dan itu memang benar: URL permanen untuk isi berbayar tidak boleh ada.
     * Penyajiannya nanti lewat temporary URL, di sprint streaming.
     */
    protected function simpanMetadata(
        Episode $episode,
        StoredFile $stored,
        string $checksum
    ): EpisodeVideo {

        return DB::transaction(function () use ($episode, $stored, $checksum) {

            $video = EpisodeVideo::updateOrCreate(
                ['episode_id' => $episode->id],
                [
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
                ]
            );

            $episode->update(['video_url' => $stored->url]);

            return $video;
        });
    }

    /**
     * Identitas disk saat diunggah: slug provider.
     *
     * Dalam multi storage tidak ada "nama disk" seperti di
     * config/filesystems.php — disk dibangun saat dibutuhkan dari baris
     * provider, dan slug itulah yang mengidentifikasinya di StorageManager.
     */
    protected function diskName(StoredFile $stored): string
    {
        // Dibaca langsung dari model, BUKAN lewat `resolveProvider()`.
        //
        // `resolveProvider()` memvalidasi ulang dan bisa melempar — dan bila
        // itu terjadi di sini, unggahan yang sudah berhasil akan dibatalkan
        // beserta objeknya dihapus, hanya karena satu kolom keterangan gagal
        // diisi. Berkasnya sudah aman di penyimpanan pada titik ini; tidak ada
        // alasan mempertaruhkannya.
        //
        // `withTrashed()` supaya provider yang baru saja dihapus tetap terbaca:
        // yang dicatat adalah ke mana berkasnya dulu dikirim, bukan apakah
        // provider itu masih dipakai hari ini.
        $slug = StorageProvider::withTrashed()
            ->whereKey($stored->providerId)
            ->value('slug');

        return (string) ($slug ?: $stored->driver->value);
    }

    /**
     * Objek sudah terunggah tetapi metadatanya gagal disimpan.
     *
     * Objeknya dihapus. Membiarkannya berarti berkas berukuran gigabyte
     * duduk di bucket tanpa satu baris pun yang mengenalinya — biaya
     * penyimpanan yang berjalan terus untuk berkas yang tidak akan pernah
     * ditemukan lagi.
     */
    protected function batalkan(
        StoredFile $stored,
        Episode $episode,
        Throwable $penyebab,
        float $mulai,
        string $mode
    ): void {

        $bersih = true;

        try {
            $this->storage->delete($stored->providerId, $stored->objectKey);
        } catch (Throwable $e) {
            $bersih = false;
        }

        $this->log('error', 'failed', [
            'episode_id'  => $episode->id,
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
     * Hapus berkas video sebelumnya setelah penggantian.
     *
     * Kegagalan di sini TIDAK membatalkan penggantian. Video baru sudah
     * tersimpan dan sudah tersambung ke episode; menggagalkan seluruh operasi
     * karena sisa berkas lama justru meninggalkan keadaan yang lebih buruk.
     * Yang tertinggal dicatat supaya bisa dibersihkan.
     */
    protected function hapusObjekLama(EpisodeVideo $lama, EpisodeVideo $baru): void
    {
        // Baris lama dan baru adalah baris yang SAMA (updateOrCreate pada
        // episode_id), jadi yang dibandingkan harus nilai object key-nya —
        // bukan id barisnya. Tanpa perbandingan ini, mengunggah dengan
        // `keep_key` akan menghapus berkas yang baru saja ditulis.
        if ($lama->object_key === $baru->object_key
            && (int) $lama->storage_provider_id === (int) $baru->storage_provider_id) {
            return;
        }

        try {
            $this->storage->delete($lama->storage_provider_id, $lama->object_key);
        } catch (Throwable $e) {
            $this->log('warning', 'orphan', [
                'episode_id'  => $lama->episode_id,
                'provider_id' => $lama->storage_provider_id,
                'object_key'  => $lama->object_key,
                'exception'   => $e::class,
                'message'     => $e->getMessage(),
                'catatan'     => 'Video lama tertinggal di penyimpanan dan perlu '
                                 .'dibersihkan manual. Penggantian tetap berhasil.',
            ]);
        }
    }

    protected function elapsed(float $mulai): float
    {
        return round((microtime(true) - $mulai) * 1000);
    }

    /**
     * Catatan khusus alur video episode.
     *
     * Ini BUKAN pengulangan pencatatan Storage Engine. Engine mencatat
     * peristiwa pada tingkat berkas (`storage.upload.success`), tanpa tahu
     * berkas itu milik episode mana. Catatan di sini menambahkan konteks
     * episode dan tahap — termasuk `started`, yang tidak dimiliki engine, dan
     * justru satu-satunya yang tercatat ketika unggahan besar mati di tengah
     * jalan tanpa pernah sampai ke baris sukses maupun gagal.
     */
    protected function log(string $level, string $event, array $context): void
    {
        Log::channel(config('storage.engine.log_channel') ?: config('logging.default'))
            ->log($level, 'episode.video.upload.'.$event, $context);
    }
}
