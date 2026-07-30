<?php

namespace App\Services;

use App\Enums\StorageDriver;
use App\Enums\StorageStatus;
use App\Models\StorageProvider;
use App\Repositories\Contracts\StorageProviderRepositoryInterface;
use App\Services\Admin\ActivityLogger;
use App\Services\Storage\Contracts\StorageManagerInterface;
use App\Services\Storage\Exceptions\StorageProviderException;
use App\Services\Storage\StorageTestResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aturan bisnis pengelolaan storage provider.
 *
 * Panel admin nanti memanggil kelas ini, bukan repository, supaya penjagaan
 * di sini tidak bisa dilewati. Penjagaan yang dimaksud terutama satu hal:
 * situs tidak boleh sampai kehilangan tujuan penyimpanan default. Provider
 * default yang hilang berarti setiap upload berikutnya gagal, dan gejalanya
 * muncul jauh dari sebabnya.
 */
class StorageProviderService
{
    public function __construct(
        protected StorageProviderRepositoryInterface $repository,
        protected StorageManagerInterface $manager,
        protected ActivityLogger $logger,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Baca
    |--------------------------------------------------------------------------
    */

    public function paginate(int $perPage = 20)
    {
        return $this->repository->paginate($perPage);
    }

    public function all(): Collection
    {
        return $this->repository->all();
    }

    public function find(int $id): StorageProvider
    {
        return $this->repository->findOrFail($id);
    }

    /**
     * Ringkasan kesehatan seluruh provider, untuk kartu di dashboard.
     */
    public function summary(): array
    {
        $providers = $this->all();

        return [
            'total'        => $providers->count(),
            'active'       => $providers->filter->isActive()->count(),
            'usable'       => $providers->filter->isUsable()->count(),
            'incomplete'   => $providers->reject->isConfigured()->count(),
            'missing_sdk'  => $providers->reject->hasAdapterInstalled()->count(),
            'has_default'  => $this->repository->findDefault() !== null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Tulis
    |--------------------------------------------------------------------------
    */

    public function store(array $data): StorageProvider
    {
        $data = $this->prepare($data);

        return DB::transaction(function () use ($data) {

            // Dikunci lebih dulu supaya pemeriksaan "belum ada provider" di
            // bawah tidak bisa dilangkahi permintaan lain yang berjalan
            // bersamaan. Tanpa ini, dua penambahan provider pertama yang tiba
            // berbarengan bisa sama-sama menyimpulkan dirinya yang pertama dan
            // sama-sama menandai diri default.
            $this->repository->lockAll();

            // Provider pertama otomatis jadi default: tanpa ini, sistem
            // berdiri dengan nol tujuan penyimpanan dan admin harus menebak
            // bahwa ada satu langkah lagi yang belum dilakukan.
            $isFirst = $this->repository->all()->isEmpty();

            if ($isFirst) {
                $data['is_default'] = true;
            }

            if (! empty($data['is_default'])) {
                $this->repository->clearDefaultFlag();
            }

            $provider = $this->repository->store($data);

            $this->logger->log('dibuat', 'storage', $provider, [
                'driver' => $provider->driver->value,
            ]);

            return $provider;
        });
    }

    public function update(StorageProvider $provider, array $data): StorageProvider
    {
        $data = $this->prepare($data, $provider);

        return DB::transaction(function () use ($provider, $data) {

            if (! empty($data['is_default'])) {
                $this->repository->lockAll();

                $this->repository->clearDefaultFlag($provider->id);
            }

            $updated = $this->repository->update($provider, $data);

            // Konfigurasi berubah, jadi disk yang sudah dibangun di
            // permintaan ini sudah kedaluwarsa.
            $this->manager->forget($updated->slug);

            $this->logger->log('diubah', 'storage', $updated, [
                'driver' => $updated->driver->value,
            ]);

            return $updated;
        });
    }

    /**
     * Kredensial yang dikirim kosong berarti "jangan ubah", bukan "hapus".
     *
     * Form admin tidak pernah menampilkan kembali secret yang tersimpan, jadi
     * field itu selalu terkirim kosong kalau admin hanya menyunting nama.
     * Tanpa penjagaan ini, satu penyuntingan sepele akan menghapus kredensial.
     */
    protected function prepare(array $data, ?StorageProvider $existing = null): array
    {
        if (isset($data['name']) && blank($data['slug'] ?? null)) {
            $data['slug'] = Str::slug($data['name']);
        }

        foreach (['access_key', 'secret_key'] as $secret) {
            if (array_key_exists($secret, $data) && blank($data[$secret])) {
                unset($data[$secret]);
            }
        }

        // Driver yang mengharuskan path-style tidak boleh dimatikan lewat form.
        if (isset($data['driver'])) {
            $driver = $data['driver'] instanceof StorageDriver
                ? $data['driver']
                : StorageDriver::tryFrom((string) $data['driver']);

            if ($driver === null) {
                throw StorageProviderException::unsupportedDriver(
                    (string) $data['driver']
                );
            }

            if ($driver->prefersPathStyle()) {
                $data['use_path_style'] = true;
            }

            if (blank($data['region'] ?? null) && $driver->defaultRegion()) {
                $data['region'] = $driver->defaultRegion();
            }
        }

        return $data;
    }

    /**
     * Hapus provider (soft delete).
     *
     * Penjagaannya dipindahkan ke dalam transaction dengan alasan yang sama
     * seperti `disable()`: memeriksa `is_default` di luar kunci memungkinkan
     * penghapusan berbarengan dengan pemindahan default, sehingga provider
     * yang baru saja dijadikan default justru ikut terhapus.
     */
    public function delete(StorageProvider $provider): void
    {
        DB::transaction(function () use ($provider) {

            $this->repository->lockAll();

            if ($provider->refresh()->is_default) {
                throw StorageProviderException::cannotDeleteDefault($provider);
            }

            $this->logger->log('dihapus', 'storage', $provider);

            $this->manager->forget($provider->slug);

            $this->repository->delete($provider);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Enable, Disable, Default, Priority
    |--------------------------------------------------------------------------
    */

    /**
     * Provider hanya boleh diaktifkan kalau lengkap dan adapternya terpasang.
     *
     * Mengaktifkan provider setengah jadi berarti memasukkannya ke rantai
     * fallback, dan kegagalannya baru terlihat saat ada berkas sungguhan
     * yang hendak disimpan.
     */
    public function enable(StorageProvider $provider): StorageProvider
    {
        if (! $provider->isConfigured()) {
            throw StorageProviderException::incomplete($provider);
        }

        if (! $provider->hasAdapterInstalled()) {
            throw StorageProviderException::adapterMissing($provider);
        }

        if ($provider->hasPlaceholders()) {
            throw StorageProviderException::hasPlaceholders($provider);
        }

        $updated = $this->repository->update($provider, [
            'status' => StorageStatus::ACTIVE->value,
        ]);

        $this->logger->log('diubah', 'storage', $updated, ['status' => 'active']);

        return $updated;
    }

    /**
     * Nonaktifkan provider.
     *
     * Penjagaan "default tidak boleh dinonaktifkan" dijalankan DI DALAM
     * transaction dengan baris terkunci, bukan di luarnya. Kalau diperiksa di
     * luar, ada celah: `disable(B)` membaca B belum default, sementara
     * `makeDefault(B)` yang berjalan bersamaan menjadikannya default. Keduanya
     * lolos, dan hasil akhirnya provider default yang nonaktif — melanggar
     * syarat bahwa default harus selalu bisa dipakai.
     *
     * Celahnya sempit, tapi akibatnya tidak: seluruh unggahan berikutnya gagal,
     * dan penyebabnya tidak terlihat dari gejalanya.
     */
    public function disable(StorageProvider $provider): StorageProvider
    {
        return DB::transaction(function () use ($provider) {

            $this->repository->lockAll();

            // Dibaca ulang setelah terkunci: nilai di objek $provider berasal
            // dari sebelum kunci didapat dan bisa sudah kedaluwarsa.
            if ($provider->refresh()->is_default) {
                throw StorageProviderException::cannotDisableDefault($provider);
            }

            $updated = $this->repository->update($provider, [
                'status' => StorageStatus::INACTIVE->value,
            ]);

            $this->manager->forget($updated->slug);

            $this->logger->log('diubah', 'storage', $updated, ['status' => 'inactive']);

            return $updated;
        });
    }

    public function toggle(StorageProvider $provider): StorageProvider
    {
        return $provider->isActive()
            ? $this->disable($provider)
            : $this->enable($provider);
    }

    /**
     * Pindahkan tanda default ke provider ini.
     *
     * Invarian yang dijaga: TEPAT SATU baris hidup boleh bertanda default.
     *
     * Tiga hal bekerja bersama untuk itu, dan ketiganya diperlukan:
     *
     * 1. Transaction — membersihkan flag lama dan memasang yang baru harus
     *    menjadi satu satuan. Kalau yang pertama berhasil dan yang kedua
     *    gagal, situs kehilangan default sepenuhnya.
     * 2. `lockAll()` — transaction saja TIDAK cukup. Dua permintaan bersamaan
     *    bisa sama-sama membersihkan flag sebelum salah satunya commit,
     *    sehingga masing-masing tidak melihat perubahan yang lain dan keduanya
     *    berakhir bertanda default.
     * 3. Urutan bersih-dulu-baru-pasang — dibalik pun hasil akhirnya sama di
     *    dalam transaction, tapi urutan ini yang membuat keadaan "dua default"
     *    tidak pernah ada bahkan sesaat di tengah transaksi.
     */
    public function makeDefault(StorageProvider $provider): StorageProvider
    {
        return DB::transaction(function () use ($provider) {

            $this->repository->lockAll();

            // Penjagaan dijalankan SETELAH kunci didapat dan baris dibaca
            // ulang. Diperiksa di luar kunci, `disable()` yang berjalan
            // bersamaan bisa menonaktifkan provider ini tepat setelah
            // pemeriksaan lolos — meninggalkan default yang tidak aktif.
            $provider->refresh();

            if (! $provider->isActive()) {
                throw StorageProviderException::cannotDefaultInactive($provider);
            }

            if (! $provider->isUsable()) {
                throw StorageProviderException::incomplete($provider);
            }

            $this->repository->clearDefaultFlag($provider->id);

            $updated = $this->repository->update($provider, [
                'is_default' => true,
            ]);

            $this->logger->log('diubah', 'storage', $updated, ['is_default' => true]);

            return $updated;
        });
    }

    /**
     * Terapkan prioritas baru untuk beberapa provider sekaligus.
     *
     * Transaction diperlukan karena ini banyak UPDATE terpisah. Kalau
     * separuhnya masuk lalu sisanya gagal, urutan yang tersimpan bukan urutan
     * lama maupun urutan baru — melainkan campuran yang tidak pernah diminta
     * siapa pun, dan tidak ada yang tahu bahwa itu yang terjadi.
     *
     * @param  array<int, int>  $priorities  id => priority
     * @return int  jumlah provider yang berubah
     */
    public function reorder(array $priorities): int
    {
        if ($priorities === []) {
            return 0;
        }

        return DB::transaction(function () use ($priorities) {

            // Hanya yang nilainya benar-benar berbeda yang ditulis. Menyimpan
            // formulir tanpa mengubah apa pun seharusnya tidak meninggalkan
            // catatan aktivitas yang berbunyi seolah ada perubahan.
            $berubah = $this->repository->changedPriorities($priorities);

            if ($berubah === []) {
                return 0;
            }

            $this->repository->applyPriorities($berubah);

            // Prioritas menentukan urutan rantai fallback, jadi disk yang
            // sudah dibangun di permintaan ini tidak lagi mencerminkan urutan
            // yang benar.
            $this->manager->forget();

            $this->logger->log('massal', 'storage', null, [
                'priority' => $berubah,
            ]);

            return count($berubah);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Test Connection
    |--------------------------------------------------------------------------
    */

    /**
     * Uji satu provider dan simpan hasilnya.
     *
     * Hasil gagal tetap dicatat — riwayat kegagalan justru yang paling
     * berguna saat menelusuri masalah.
     */
    public function test(StorageProvider $provider): StorageTestResult
    {
        $result = $this->manager->test($provider);

        $this->repository->recordTest(
            $provider,
            $result->status(),
            $result->message
        );

        return $result;
    }

    /**
     * Uji semua provider yang lengkap, termasuk yang masih nonaktif.
     *
     * @return array<string, StorageTestResult>  slug => hasil
     */
    public function testAll(): array
    {
        $results = [];

        foreach ($this->all() as $provider) {
            if (! $provider->isConfigured() || ! $provider->hasAdapterInstalled()) {
                continue;
            }

            $results[$provider->slug] = $this->test($provider);
        }

        return $results;
    }
}
