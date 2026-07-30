<?php

namespace App\Services\Storage;

use App\Enums\StorageCollection;
use App\Models\StorageProvider;
use App\Repositories\Contracts\StorageProviderRepositoryInterface;
use App\Services\Storage\Contracts\StorageEngineInterface;
use App\Services\Storage\Contracts\StorageManagerInterface;
use App\Services\Storage\Exceptions\StorageEngineException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Implementasi Storage Engine.
 *
 * Berdiri di atas pondasi Sprint 7.1–7.3 dan tidak mengubahnya: provider
 * dibaca lewat StorageProviderRepository, disk dibangun lewat StorageManager,
 * dan konfigurasi disk tetap diterjemahkan DiskConfigFactory. Engine ini tidak
 * pernah memanggil `Storage::` sendiri — satu-satunya jalan ke disk adalah
 * `StorageManager::build()`.
 *
 * Yang ditambahkan engine adalah lapisan di atasnya: pembangunan object key
 * yang aman, validasi kesiapan provider, objek hasil yang lengkap, dan
 * pencatatan.
 */
class StorageEngine implements StorageEngineInterface
{
    public function __construct(
        protected StorageManagerInterface $manager,
        protected StorageProviderRepositoryInterface $repository,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Pemilihan dan validasi provider
    |--------------------------------------------------------------------------
    */

    public function resolveProvider(int|string|null $provider = null): StorageProvider
    {
        $resolved = $this->findProvider($provider);

        $this->assertReady($resolved);

        return $resolved;
    }

    /**
     * Cari provider tanpa memvalidasinya.
     *
     * `null` berarti mode Auto. Yang dipakai adalah `defaultProvider()` dari
     * StorageManager, bukan query sendiri — di sanalah aturan "slug di config
     * menang atas kolom is_default" sudah ditegakkan sejak 7.1, dan
     * menuliskannya ulang di sini berarti dua aturan yang bisa berbeda.
     */
    protected function findProvider(int|string|null $provider): StorageProvider
    {
        if ($provider === null) {
            return $this->manager->defaultProvider()
                ?? throw StorageEngineException::noDefaultProvider();
        }

        // Angka dalam bentuk string tetap dianggap id. Nilai dari form dan
        // dari query string selalu datang sebagai string.
        if (is_int($provider) || ctype_digit((string) $provider)) {
            return $this->repository->find((int) $provider)
                ?? throw StorageEngineException::providerNotFound($provider);
        }

        return $this->repository->findBySlug((string) $provider)
            ?? throw StorageEngineException::providerNotFound($provider);
    }

    /**
     * Empat pemeriksaan yang diminta spesifikasi sprint, plus satu.
     *
     * 1. Provider aktif.
     * 2. Driver tersedia — paket composer adapternya benar-benar ada di
     *    vendor/. Tanpa ini, provider yang tampak benar gagal saat disk
     *    dibangun, dengan galat Flysystem yang tidak menyebut sebabnya.
     * 3. Bucket tersedia — tercakup `isConfigured()`, karena `bucket` ada di
     *    `requiredFields()` setiap driver awan. Provider lokal tidak
     *    memerlukan bucket, dan memaksanya justru salah.
     * 4. Nilai contoh sudah diganti. Bukan permintaan spesifikasi, tapi
     *    penjagaan dari 7.3 yang sudah terbukti berguna: endpoint R2 dengan
     *    `ACCOUNT_ID` yang belum diganti menghasilkan galat TLS yang membuat
     *    orang memeriksa sertifikat, padahal host-nya tidak ada.
     *
     * Soal "koneksi valid": engine TIDAK menguji koneksi sebelum setiap
     * operasi. Menghubungi penyimpanan dua kali untuk satu unggahan
     * menggandakan waktu tunggu, dan tetap tidak menjamin apa pun — provider
     * bisa rusak di antara pemeriksaan dan operasinya. Yang menentukan adalah
     * operasinya sendiri, yang kegagalannya ditangkap, dicatat, dan
     * disampaikan. Bila Anda tetap ingin menolak provider yang belum pernah
     * lulus Test Connection, nyalakan
     * `storage.engine.require_verified_connection`.
     *
     * @throws StorageEngineException
     */
    protected function assertReady(StorageProvider $provider): void
    {
        if (! $provider->isActive()) {
            throw StorageEngineException::providerInactive($provider);
        }

        if (! $provider->hasAdapterInstalled()) {
            throw StorageEngineException::driverUnavailable($provider);
        }

        if (! $provider->isConfigured()) {
            throw StorageEngineException::providerIncomplete($provider);
        }

        if ($provider->hasPlaceholders()) {
            throw StorageEngineException::providerHasPlaceholders($provider);
        }

        if ($this->requiresVerifiedConnection() && ! $this->hasPassedTest($provider)) {
            throw StorageEngineException::connectionNotVerified($provider);
        }
    }

    protected function requiresVerifiedConnection(): bool
    {
        return (bool) config('storage.engine.require_verified_connection', false);
    }

    protected function hasPassedTest(StorageProvider $provider): bool
    {
        return $provider->last_test_status === 'ok';
    }

    protected function disk(StorageProvider $provider): Filesystem
    {
        return $this->manager->build($provider);
    }

    /*
    |--------------------------------------------------------------------------
    | Tulis
    |--------------------------------------------------------------------------
    */

    public function upload(
        UploadedFile $file,
        StorageCollection $collection,
        int|string|null $provider = null,
        array $options = []
    ): StoredFile {

        $this->assertUploadValid($file);

        $this->assertAllowedByCollection($file, $collection);

        return $this->uploadTo(
            $file,
            $collection->directory(),
            $provider,
            $options + ['visibility' => $collection->visibility()]
        );
    }

    public function uploadTo(
        UploadedFile $file,
        string $directory,
        int|string|null $provider = null,
        array $options = []
    ): StoredFile {

        $this->assertUploadValid($file);

        $resolved = $this->resolveProvider($provider);

        // Nama tersimpan selalu dibangun engine, bukan diambil dari peramban.
        $filename = isset($options['filename'])
            ? ObjectKey::filename((string) $options['filename'])
            : ObjectKey::randomName($file->getClientOriginalExtension());

        $key = ObjectKey::join($directory, $filename);

        $this->assertExtensionNotBlocked($key);

        $visibility = $this->visibilityFor($resolved, $options);

        // Nilai ini dibaca SEBELUM berkas dipindahkan. Setelah `putFileAs`
        // berjalan, berkas sementaranya sudah tidak ada di tempatnya dan
        // getSize() maupun getMimeType() akan gagal.
        $size = (int) $file->getSize();
        $mime = $this->mimeOf($file);
        $originalName = $this->originalNameOf($file);

        $mulai = microtime(true);

        try {
            $this->disk($resolved)->putFileAs(
                ObjectKey::directoryOf($key),
                $file,
                ObjectKey::basenameOf($key),
                $visibility
            );
        } catch (Throwable $e) {
            $this->logFailure('upload', $resolved, $key, $e, [
                'mime_type' => $mime,
                'size'      => $size,
            ]);

            throw StorageEngineException::operationFailed('upload', $resolved, $key, $e);
        }

        $stored = StoredFile::make(
            provider: $resolved,
            objectKey: $key,
            originalName: $originalName,
            mimeType: $mime,
            size: $size,
            url: $this->publicUrl($resolved, $key, $visibility),
            visibility: $visibility,
        );

        $this->logSuccess('upload', $stored, $mulai);

        return $stored;
    }

    public function putContents(
        string $contents,
        string $directory,
        string $filename,
        int|string|null $provider = null,
        array $options = []
    ): StoredFile {

        $resolved = $this->resolveProvider($provider);

        $key = ObjectKey::join($directory, ObjectKey::filename($filename));

        $this->assertExtensionNotBlocked($key);

        $visibility = $this->visibilityFor($resolved, $options);

        $mulai = microtime(true);

        try {
            $this->disk($resolved)->put($key, $contents, $visibility);
        } catch (Throwable $e) {
            $this->logFailure('upload', $resolved, $key, $e, [
                'size' => strlen($contents),
            ]);

            throw StorageEngineException::operationFailed('upload', $resolved, $key, $e);
        }

        $stored = StoredFile::make(
            provider: $resolved,
            objectKey: $key,
            originalName: ObjectKey::basenameOf($key),

            // Isi yang dibuat program tidak punya mime yang dilaporkan
            // peramban. Ditebak dari ekstensinya, dan bila tidak dikenali
            // dipakai tipe biner umum — bukan dikosongkan, karena sebagian
            // provider menolak objek tanpa Content-Type.
            mimeType: $options['mime_type'] ?? $this->guessMime($key),
            size: strlen($contents),
            url: $this->publicUrl($resolved, $key, $visibility),
            visibility: $visibility,
        );

        $this->logSuccess('upload', $stored, $mulai);

        return $stored;
    }

    public function replace(
        int|string $provider,
        string $objectKey,
        UploadedFile $file,
        array $options = []
    ): StoredFile {

        $resolved = $this->resolveProvider($provider);

        $lama = ObjectKey::assertSafe($objectKey);

        $direktori = $options['directory'] ?? ObjectKey::directoryOf($lama);

        // Menimpa key yang sama membuat CDN dan peramban tetap menyajikan
        // berkas lama. Bawaannya menulis key baru; menimpa hanya kalau diminta.
        if (! empty($options['keep_key'])) {
            $options['filename'] = ObjectKey::basenameOf($lama);
        }

        $baru = $this->uploadTo($file, $direktori, $provider, $options);

        if ($baru->objectKey !== $lama) {
            // Kegagalan menghapus berkas lama TIDAK membatalkan penggantian.
            // Berkas baru sudah tersimpan dan sudah dikembalikan; menggagalkan
            // seluruh operasi karena sisa berkas lama justru meninggalkan
            // keadaan yang lebih buruk. Yang tertinggal dicatat di log.
            $this->deleteQuietly($resolved, $lama);
        }

        return $baru;
    }

    /*
    |--------------------------------------------------------------------------
    | Pindah dan salin
    |--------------------------------------------------------------------------
    */

    public function rename(
        int|string $provider,
        string $objectKey,
        string $newFilename
    ): StoredFile {

        $lama = ObjectKey::assertSafe($objectKey);

        return $this->relocate(
            $provider,
            $lama,
            ObjectKey::join(ObjectKey::directoryOf($lama), $newFilename),
            'rename'
        );
    }

    public function move(
        int|string $provider,
        string $objectKey,
        string $newDirectory
    ): StoredFile {

        $lama = ObjectKey::assertSafe($objectKey);

        return $this->relocate(
            $provider,
            $lama,
            ObjectKey::join($newDirectory, ObjectKey::basenameOf($lama)),
            'move'
        );
    }

    public function copy(
        int|string $provider,
        string $objectKey,
        string $targetDirectory,
        ?string $targetFilename = null
    ): StoredFile {

        $sumber = ObjectKey::assertSafe($objectKey);

        $tujuan = ObjectKey::join(
            $targetDirectory,
            $targetFilename ?? ObjectKey::basenameOf($sumber)
        );

        return $this->relocate($provider, $sumber, $tujuan, 'copy');
    }

    /**
     * Satu jalur untuk rename, move, dan copy.
     *
     * Ketiganya hanya berbeda pada cara menghitung key tujuan dan apakah
     * sumbernya ikut dihapus. Menulis tiga method yang isinya sama akan
     * berarti tiga tempat yang harus ingat memeriksa keberadaan sumber,
     * menolak menimpa tujuan, dan menangkap kegagalan.
     *
     * @throws StorageEngineException
     */
    protected function relocate(
        int|string $provider,
        string $sumber,
        string $tujuan,
        string $operation
    ): StoredFile {

        $resolved = $this->resolveProvider($provider);

        $disk = $this->disk($resolved);

        if (! $disk->exists($sumber)) {
            throw StorageEngineException::notFound($resolved, $sumber);
        }

        // Tujuan yang sama dengan sumber: tidak melakukan apa-apa lebih baik
        // daripada menghapus lalu menulis ulang berkas yang sama.
        if ($sumber === $tujuan) {
            return $this->describe($resolved, $sumber);
        }

        if ($disk->exists($tujuan)) {
            throw StorageEngineException::invalidFilename(
                $tujuan,
                'sudah ada berkas lain dengan object key itu'
            );
        }

        try {
            $operation === 'copy'
                ? $disk->copy($sumber, $tujuan)
                : $disk->move($sumber, $tujuan);
        } catch (Throwable $e) {
            $this->logFailure($operation, $resolved, $sumber, $e, [
                'target' => $tujuan,
            ]);

            throw StorageEngineException::operationFailed($operation, $resolved, $sumber, $e);
        }

        $hasil = $this->describe($resolved, $tujuan);

        $this->log('info', $operation, $hasil->logContext() + ['source' => $sumber]);

        return $hasil;
    }

    /*
    |--------------------------------------------------------------------------
    | Hapus
    |--------------------------------------------------------------------------
    */

    public function delete(int|string $provider, string $objectKey): bool
    {
        $resolved = $this->resolveProvider($provider);

        $key = ObjectKey::assertSafe($objectKey);

        $disk = $this->disk($resolved);

        // Berkas yang memang sudah tidak ada bukan kegagalan. Penghapusan
        // harus idempoten supaya kode pembersih tidak gagal hanya karena
        // pekerjaannya sudah dilakukan.
        if (! $disk->exists($key)) {
            $this->log('info', 'delete.absent', $this->context($resolved, $key));

            return false;
        }

        try {
            $disk->delete($key);
        } catch (Throwable $e) {
            $this->logFailure('delete', $resolved, $key, $e);

            throw StorageEngineException::operationFailed('delete', $resolved, $key, $e);
        }

        $this->log('info', 'delete.success', $this->context($resolved, $key));

        return true;
    }

    /**
     * Hapus tanpa melempar apa pun. Dipakai pembersihan yang tidak boleh
     * menggagalkan operasi utamanya.
     */
    protected function deleteQuietly(StorageProvider $provider, string $key): void
    {
        try {
            $this->delete((int) $provider->getKey(), $key);
        } catch (Throwable $e) {
            $this->log('warning', 'delete.orphan', $this->context($provider, $key) + [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'catatan'   => 'Berkas lama tertinggal di penyimpanan dan perlu '
                               .'dibersihkan manual. Operasi utamanya tetap berhasil.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Baca
    |--------------------------------------------------------------------------
    */

    public function exists(int|string $provider, string $objectKey): bool
    {
        $resolved = $this->resolveProvider($provider);

        return $this->disk($resolved)->exists(ObjectKey::assertSafe($objectKey));
    }

    public function metadata(int|string $provider, string $objectKey): FileMetadata
    {
        $resolved = $this->resolveProvider($provider);

        $key = ObjectKey::assertSafe($objectKey);

        $disk = $this->disk($resolved);

        if (! $disk->exists($key)) {
            return FileMetadata::missing((int) $resolved->getKey(), $key);
        }

        $visibility = $this->quietly(fn () => $disk->getVisibility($key));

        return new FileMetadata(
            providerId: (int) $resolved->getKey(),
            objectKey: $key,
            exists: true,

            // Tiap pembacaan dibungkus sendiri-sendiri. Provider yang tidak
            // melaporkan salah satunya tidak boleh membuat seluruh metadata
            // gagal — mime dan visibility khususnya sering tidak tersedia.
            size: $this->quietly(fn () => (int) $disk->size($key)),
            mimeType: $this->quietly(fn () => $disk->mimeType($key)) ?: null,
            lastModified: $this->quietly(
                fn () => Carbon::createFromTimestamp($disk->lastModified($key))
            ),
            visibility: $visibility,
            url: $this->publicUrl($resolved, $key, $visibility ?? 'private'),
        );
    }

    public function url(int|string $provider, string $objectKey): ?string
    {
        $resolved = $this->resolveProvider($provider);

        $key = ObjectKey::assertSafe($objectKey);

        return $this->publicUrl($resolved, $key, 'public');
    }

    public function temporaryUrl(
        int|string $provider,
        string $objectKey,
        int $minutes = 60,
        bool $strict = true
    ): ?string {

        $resolved = $this->resolveProvider($provider);

        $key = ObjectKey::assertSafe($objectKey);

        $disk = $this->disk($resolved);

        // Penyimpanan lokal tidak punya konsep URL bertanda tangan. Yang
        // dilempar Laravel di situ adalah RuntimeException dengan pesan
        // "does not support creating temporary URLs", yang tidak memberi tahu
        // apa yang harus dilakukan.
        if (! method_exists($disk, 'temporaryUrl')) {
            if ($strict) {
                throw StorageEngineException::temporaryUrlUnsupported($resolved);
            }

            return null;
        }

        try {
            return $disk->temporaryUrl($key, now()->addMinutes(max(1, $minutes)));
        } catch (Throwable $e) {
            if ($strict) {
                throw StorageEngineException::temporaryUrlUnsupported($resolved);
            }

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Susun StoredFile untuk berkas yang sudah ada di penyimpanan.
     */
    protected function describe(StorageProvider $provider, string $key): StoredFile
    {
        $disk = $this->disk($provider);

        $visibility = $this->quietly(fn () => $disk->getVisibility($key))
            ?? $provider->visibility
            ?? 'private';

        return StoredFile::make(
            provider: $provider,
            objectKey: $key,

            // Berkas yang sudah tersimpan tidak lagi membawa nama aslinya;
            // yang paling mendekati adalah nama tersimpannya.
            originalName: ObjectKey::basenameOf($key),
            mimeType: $this->quietly(fn () => $disk->mimeType($key)) ?: $this->guessMime($key),
            size: (int) ($this->quietly(fn () => $disk->size($key)) ?? 0),
            url: $this->publicUrl($provider, $key, $visibility),
            visibility: $visibility,
        );
    }

    /**
     * URL publik, atau null bila tidak bisa disusun.
     *
     * Berkas privat sengaja TIDAK diberi URL publik meskipun providernya bisa
     * menyusunnya. Menyertakan URL permanen untuk video berbayar di objek
     * hasil adalah cara paling mudah agar URL itu akhirnya bocor ke HTML.
     * Untuk berkas privat, pakai temporaryUrl().
     */
    protected function publicUrl(
        StorageProvider $provider,
        string $key,
        string $visibility
    ): ?string {

        if ($visibility !== 'public') {
            return null;
        }

        $disk = $this->disk($provider);

        if (! method_exists($disk, 'url')) {
            return null;
        }

        return $this->quietly(fn () => $disk->url($key));
    }

    /**
     * Jalankan sesuatu yang boleh gagal, kembalikan null bila gagal.
     *
     * Dipakai hanya untuk pembacaan metadata tambahan. TIDAK dipakai untuk
     * operasi tulis — kegagalan menulis harus selalu terdengar.
     */
    protected function quietly(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return null;
        }
    }

    protected function visibilityFor(StorageProvider $provider, array $options): string
    {
        $visibility = $options['visibility']
            ?? $provider->visibility
            ?? config('storage.default_visibility', 'private');

        return in_array($visibility, ['public', 'private'], true)
            ? $visibility
            : 'private';
    }

    /**
     * @throws StorageEngineException
     */
    protected function assertUploadValid(UploadedFile $file): void
    {
        // Berkas yang gagal terkirim tetap sampai ke controller sebagai
        // UploadedFile. Tanpa pemeriksaan ini, berkas potong (mis. karena
        // melewati upload_max_filesize) akan tersimpan sebagai objek rusak
        // berukuran 0 byte, dan barunya ketahuan saat ada yang memutarnya.
        if (! $file->isValid()) {
            throw StorageEngineException::invalidUpload(
                $file->getErrorMessage() ?: 'pengunggahan tidak selesai'
            );
        }
    }

    /**
     * Tolak ekstensi yang dieksekusi server, apa pun koleksinya.
     *
     * Berlaku pada SEMUA jalur tulis, termasuk `uploadTo()` dan koleksi ASSET
     * yang sengaja tidak membatasi ekstensi. Diperiksa pada object key yang
     * SUDAH final — bukan pada nama yang dikirim peramban — supaya yang dinilai
     * memang ekstensi yang akan benar-benar tersimpan.
     *
     * @throws StorageEngineException
     */
    protected function assertExtensionNotBlocked(string $key): void
    {
        $extension = ObjectKey::extension(pathinfo($key, PATHINFO_EXTENSION) ?: null);

        if ($extension === null) {
            return;
        }

        $blocked = array_map(
            'strtolower',
            (array) config('storage.engine.blocked_extensions', [])
        );

        if (in_array($extension, $blocked, true)) {
            throw StorageEngineException::blockedExtension($extension);
        }
    }

    /**
     * @throws StorageEngineException
     */
    protected function assertAllowedByCollection(
        UploadedFile $file,
        StorageCollection $collection
    ): void {

        $extension = ObjectKey::extension($file->getClientOriginalExtension());

        $allowed = $collection->extensions();

        if ($allowed !== [] && ! in_array((string) $extension, $allowed, true)) {
            throw StorageEngineException::extensionNotAllowed($collection, $extension);
        }

        $maxKb = $collection->maxKb();

        if ($maxKb !== null) {
            $sizeKb = (int) ceil(((int) $file->getSize()) / 1024);

            if ($sizeKb > $maxKb) {
                throw StorageEngineException::tooLarge($collection, $sizeKb, $maxKb);
            }
        }
    }

    /**
     * Mime yang dilaporkan sistem, bukan yang diakui peramban.
     *
     * `getClientMimeType()` datang dari peramban dan bisa dipalsukan.
     * `getMimeType()` menebaknya dari isi berkas.
     */
    protected function mimeOf(UploadedFile $file): string
    {
        return $this->quietly(fn () => $file->getMimeType())
            ?: ($file->getClientMimeType() ?: 'application/octet-stream');
    }

    protected function originalNameOf(UploadedFile $file): string
    {
        $name = trim((string) $file->getClientOriginalName());

        // Nama asli hanya untuk ditampilkan, tapi tetap dibersihkan: nilainya
        // datang dari peramban dan akan dirender di halaman admin.
        $name = basename(str_replace('\\', '/', $name));

        return $name === '' ? 'tanpa-nama' : mb_substr($name, 0, 255);
    }

    protected function guessMime(string $key): string
    {
        $extension = ObjectKey::extension(pathinfo($key, PATHINFO_EXTENSION) ?: null);

        return match ($extension) {
            'mp4'          => 'video/mp4',
            'mkv'          => 'video/x-matroska',
            'webm'         => 'video/webm',
            'mov'          => 'video/quicktime',
            'm4v'          => 'video/x-m4v',
            'jpg', 'jpeg'  => 'image/jpeg',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
            'srt'          => 'application/x-subrip',
            'vtt'          => 'text/vtt',
            'ass', 'ssa'   => 'text/plain',
            'json'         => 'application/json',
            'txt'          => 'text/plain',
            default        => 'application/octet-stream',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Pencatatan
    |--------------------------------------------------------------------------
    */

    protected function context(StorageProvider $provider, string $key): array
    {
        return [
            'provider_id'   => (int) $provider->getKey(),
            'provider_slug' => $provider->slug,
            'driver'        => $provider->driver->value,
            'bucket'        => $provider->bucket,
            'object_key'    => $key,
        ];
    }

    protected function logSuccess(string $operation, StoredFile $file, float $mulai): void
    {
        $this->log('info', $operation.'.success', $file->logContext() + [
            'duration_ms' => round((microtime(true) - $mulai) * 1000),
        ]);
    }

    protected function logFailure(
        string $operation,
        StorageProvider $provider,
        string $key,
        Throwable $e,
        array $extra = []
    ): void {

        $this->log('error', $operation.'.failed', $this->context($provider, $key) + $extra + [
            'exception' => $e::class,
            'message'   => $e->getMessage(),
        ]);
    }

    /**
     * Tulis ke log Laravel.
     *
     * Yang TIDAK pernah masuk ke sini: `access_key`, `secret_key`, dan isi
     * berkas. Konteks log dibangun hanya dari field yang sudah disaring
     * `context()` dan `StoredFile::logContext()`, jadi kredensial tidak bisa
     * ikut terbawa hanya karena seseorang menambahkan field baru ke provider.
     */
    protected function log(string $level, string $event, array $context): void
    {
        if (! config('storage.engine.logging', true)) {
            return;
        }

        Log::channel(config('storage.engine.log_channel') ?: config('logging.default'))
            ->log($level, 'storage.'.$event, $context);
    }
}
