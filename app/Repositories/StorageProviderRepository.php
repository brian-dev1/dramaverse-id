<?php

namespace App\Repositories;

use App\Models\StorageProvider;
use App\Repositories\Contracts\StorageProviderRepositoryInterface;
use Illuminate\Support\Collection;

class StorageProviderRepository implements StorageProviderRepositoryInterface
{
    public function paginate(int $perPage = 20)
    {
        return StorageProvider::query()
            ->byPriority()
            ->paginate($perPage);
    }

    public function all(): Collection
    {
        return StorageProvider::query()
            ->byPriority()
            ->get();
    }

    public function activeByPriority(): Collection
    {
        return StorageProvider::query()
            ->active()
            ->byPriority()
            ->get();
    }

    public function find(int $id): ?StorageProvider
    {
        return StorageProvider::find($id);
    }

    public function findOrFail(int $id): StorageProvider
    {
        return StorageProvider::findOrFail($id);
    }

    public function findBySlug(string $slug): ?StorageProvider
    {
        return StorageProvider::where('slug', $slug)->first();
    }

    public function findDefault(): ?StorageProvider
    {
        // Default yang sudah dinonaktifkan tidak boleh dipakai. Kalau ini
        // terjadi, firstUsable() yang mengambil alih.
        return StorageProvider::query()
            ->isDefault()
            ->active()
            ->first();
    }

    public function firstUsable(): ?StorageProvider
    {
        return $this->activeByPriority()
            ->first(fn (StorageProvider $provider) => $provider->isUsable());
    }

    public function store(array $data): StorageProvider
    {
        return StorageProvider::create($data);
    }

    public function update(
        StorageProvider $provider,
        array $data
    ): StorageProvider {

        $provider->update($data);

        return $provider->refresh();
    }

    public function delete(
        StorageProvider $provider
    ): void {

        $provider->delete();

    }

    public function clearDefaultFlag(?int $exceptId = null): void
    {
        StorageProvider::query()
            ->where('is_default', true)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }

    public function recordTest(
        StorageProvider $provider,
        string $status,
        string $message
    ): StorageProvider {

        $provider->update([

            'last_tested_at' => now(),

            'last_test_status' => $status,

            // Pesan dari SDK penyimpanan bisa memuat seluruh jejak permintaan.
            // Dipotong supaya tidak melewati batas kolom TEXT.
            'last_test_message' => mb_substr($message, 0, 2000),

        ]);

        return $provider->refresh();
    }

    public function applyPriorities(array $priorities): void
    {
        foreach ($priorities as $id => $priority) {
            StorageProvider::where('id', (int) $id)
                ->update(['priority' => (int) $priority]);
        }
    }

    public function countActive(): int
    {
        return StorageProvider::query()->active()->count();
    }
}
