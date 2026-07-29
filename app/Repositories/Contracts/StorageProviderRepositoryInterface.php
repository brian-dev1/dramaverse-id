<?php

namespace App\Repositories\Contracts;

use App\Models\StorageProvider;
use Illuminate\Support\Collection;

interface StorageProviderRepositoryInterface
{
    public function paginate(int $perPage = 20);

    /**
     * @return Collection<int, StorageProvider>
     */
    public function all(): Collection;

    /**
     * Provider aktif, urut prioritas (angka kecil lebih dulu).
     *
     * @return Collection<int, StorageProvider>
     */
    public function activeByPriority(): Collection;

    public function find(int $id): ?StorageProvider;

    public function findOrFail(int $id): StorageProvider;

    public function findBySlug(string $slug): ?StorageProvider;

    public function findDefault(): ?StorageProvider;

    /**
     * Provider aktif pertama menurut prioritas. Jaring pengaman ketika
     * tidak ada satu pun baris ditandai default.
     */
    public function firstUsable(): ?StorageProvider;

    public function store(array $data): StorageProvider;

    public function update(StorageProvider $provider, array $data): StorageProvider;

    public function delete(StorageProvider $provider): void;

    /**
     * Lepas tanda default dari semua baris kecuali $exceptId.
     */
    public function clearDefaultFlag(?int $exceptId = null): void;

    /**
     * Simpan hasil Test Connection terakhir.
     */
    public function recordTest(
        StorageProvider $provider,
        string $status,
        string $message
    ): StorageProvider;

    /**
     * Terapkan urutan prioritas baru secara massal.
     *
     * @param  array<int, int>  $priorities  id => priority
     */
    public function applyPriorities(array $priorities): void;

    public function countActive(): int;
}
