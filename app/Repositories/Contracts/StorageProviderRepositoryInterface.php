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
     * Kunci seluruh baris sampai transaksi berjalan selesai.
     *
     * Wajib dipanggil sebelum memindahkan tanda default. Transaction sendirian
     * tidak menjamin hanya ada satu default: dua permintaan bersamaan bisa
     * sama-sama membersihkan flag sebelum salah satunya commit, sehingga
     * keduanya tidak melihat perubahan yang lain dan berakhir dengan dua baris
     * bertanda default.
     */
    public function lockAll(): void;

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

    /**
     * Saring $priorities, sisakan yang nilainya benar-benar berbeda dari
     * yang tersimpan. Id yang tidak ada di database ikut dibuang.
     *
     * @param  array<int, int>  $priorities  id => priority
     * @return array<int, int>
     */
    public function changedPriorities(array $priorities): array;

    public function countActive(): int;
}
