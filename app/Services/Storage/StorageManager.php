<?php

namespace App\Services\Storage;

use App\Models\StorageProvider;
use App\Repositories\Contracts\StorageProviderRepositoryInterface;
use App\Services\Storage\Contracts\StorageManagerInterface;
use App\Services\Storage\Exceptions\StorageProviderException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Implementasi pintu masuk storage.
 *
 * Disk dibangun saat dibutuhkan lewat Storage::build(), bukan didaftarkan di
 * config/filesystems.php. Alasannya: daftar provider ada di database dan bisa
 * berubah kapan saja, sedangkan config di-cache oleh `config:cache` dan hanya
 * ikut berubah kalau di-deploy ulang.
 *
 * Instance disk dimemoisasi per slug selama satu permintaan. Membangun klien
 * S3 tidak gratis, dan satu permintaan bisa menyentuh disk yang sama
 * berkali-kali.
 */
class StorageManager implements StorageManagerInterface
{
    /**
     * @var array<string, Filesystem>
     */
    protected array $disks = [];

    public function __construct(
        protected StorageProviderRepositoryInterface $repository,
        protected DiskConfigFactory $configFactory,
    ) {
    }

    public function disk(?string $slug = null): Filesystem
    {
        $provider = $this->provider($slug);

        if (! $provider->isActive()) {
            throw StorageProviderException::inactive($provider);
        }

        return $this->build($provider);
    }

    public function default(): Filesystem
    {
        return $this->disk();
    }

    /**
     * Membangun disk dari sebuah provider tanpa memeriksa statusnya.
     *
     * Dipakai oleh Test Connection, yang memang harus bisa menguji provider
     * yang belum diaktifkan.
     */
    public function build(StorageProvider $provider): Filesystem
    {
        $key = $provider->slug;

        if (isset($this->disks[$key])) {
            return $this->disks[$key];
        }

        $config = $this->configFactory->make($provider);

        return $this->disks[$key] = Storage::build($config);
    }

    public function provider(?string $slug = null): StorageProvider
    {
        if ($slug === null) {
            return $this->defaultProvider()
                ?? throw StorageProviderException::noDefault();
        }

        return $this->repository->findBySlug($slug)
            ?? throw StorageProviderException::notFound($slug);
    }

    public function defaultProvider(): ?StorageProvider
    {
        // Slug di config menang atas kolom is_default. Ini jalan keluar
        // darurat: kalau provider default di database rusak, operator bisa
        // memaksa provider lain lewat .env tanpa menyentuh database.
        $forced = config('storage.default');

        if (filled($forced)) {
            $provider = $this->repository->findBySlug($forced);

            if ($provider) {
                return $provider;
            }
        }

        return $this->repository->findDefault()
            ?? $this->repository->firstUsable();
    }

    public function usableProviders(): Collection
    {
        return $this->repository
            ->activeByPriority()
            ->filter(fn (StorageProvider $p) => $p->isUsable())
            ->values();
    }

    public function chain(): Collection
    {
        $usable = $this->usableProviders();

        $default = $this->defaultProvider();

        // Default yang tidak layak pakai TIDAK ditaruh di depan rantai.
        // Menaruhnya di sana berarti setiap upload memulai dengan satu
        // percobaan yang pasti gagal, dan kegagalan itu memakan kuota
        // percobaan yang seharusnya dipakai provider yang sehat.
        if ($default === null || ! $default->isUsable()) {
            return $usable;
        }

        // Provider default dipindah ke depan, sisanya tetap urut prioritas.
        return $usable
            ->reject(fn (StorageProvider $p) => $p->id === $default->id)
            ->prepend($default)
            ->values();
    }

    /**
     * Test Connection.
     *
     * Menulis berkas kecil, membacanya kembali, lalu menghapusnya. Ketiganya
     * diperlukan: kredensial yang hanya punya izin tulis akan lolos kalau
     * yang diuji hanya tulis, lalu gagal saat berkas hendak dibaca pengguna.
     *
     * Blok finally memastikan berkas uji dihapus meskipun pembacaan gagal,
     * supaya bucket tidak menumpuk sampah tiap kali test dijalankan.
     */
    public function test(StorageProvider $provider): StorageTestResult
    {
        // Ditolak sebelum jaringan disentuh. Nilai contoh yang belum diganti
        // menghasilkan galat TLS atau DNS yang menyesatkan, dan menelusurinya
        // jauh lebih mahal daripada memeriksanya di sini.
        if ($provider->hasPlaceholders()) {
            $parts = [];

            foreach ($provider->placeholderFields() as $field => $token) {
                $parts[] = "{$field} (masih memuat \"{$token}\")";
            }

            return StorageTestResult::fail(
                'Masih ada nilai contoh yang belum diganti: '
                .implode(', ', $parts).'.'
            );
        }

        $path = $this->probePath();

        $payload = 'dramaverse-storage-test '.now()->toIso8601String();

        $startedAt = microtime(true);

        $disk = null;

        try {
            $disk = $this->build($provider);

            $disk->put($path, $payload);

            $readBack = $disk->get($path);

            $elapsed = $this->elapsedMs($startedAt);

            if ($readBack !== $payload) {
                return StorageTestResult::fail(
                    'Berkas uji berhasil ditulis tetapi isinya berbeda saat dibaca '
                    .'ulang. Periksa apakah bucket ini dilayani cache atau CDN '
                    .'yang menyajikan versi lama.',
                    $elapsed
                );
            }

            return StorageTestResult::pass(
                'Tulis, baca, dan hapus berhasil.',
                $elapsed
            );

        } catch (Throwable $e) {

            return StorageTestResult::fromException(
                $e,
                $this->elapsedMs($startedAt)
            );

        } finally {

            if ($disk !== null) {
                // Kegagalan pembersihan tidak boleh menutupi hasil test.
                try {
                    $disk->delete($path);
                } catch (Throwable) {
                    // Sengaja diabaikan.
                }
            }

            // Disk uji tidak disimpan: provider yang baru diuji sering
            // langsung disunting setelahnya.
            $this->forget($provider->slug);
        }
    }

    public function forget(?string $slug = null): void
    {
        if ($slug === null) {
            $this->disks = [];

            return;
        }

        unset($this->disks[$slug]);
    }

    /**
     * Path berkas uji. Diberi nama acak agar dua test yang berjalan
     * bersamaan tidak saling menghapus berkas uji satu sama lain.
     */
    protected function probePath(): string
    {
        $directory = trim((string) config('storage.probe.directory', '_healthcheck'), '/');

        $filename = config('storage.probe.filename', 'connection-test');

        return $directory.'/'.$filename.'-'.bin2hex(random_bytes(8)).'.txt';
    }

    protected function elapsedMs(float $startedAt): float
    {
        return (microtime(true) - $startedAt) * 1000;
    }
}
