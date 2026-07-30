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

    /**
     * Kunci seluruh baris sampai transaksi berjalan selesai.
     *
     * Sengaja mengunci SEMUA baris, bukan hanya yang sedang bertanda default.
     * Kalau yang dikunci hanya baris ber-`is_default = true`, dua permintaan
     * bersamaan pada tabel yang belum punya default tidak akan mengunci apa
     * pun — tidak ada baris yang cocok — lalu keduanya lolos dan menghasilkan
     * dua default. Mengunci semua baris menghilangkan celah itu, dan tabel ini
     * memang kecil (jumlah provider dihitung dengan jari), jadi biayanya
     * tidak berarti.
     *
     * `withTrashed()` disertakan supaya baris terhapus ikut terkunci: baris
     * terhapus masih bisa dipulihkan, dan pemulihan tidak boleh berbarengan
     * dengan pemindahan default.
     *
     * Di SQLite (dipakai pengujian) `lockForUpdate()` tidak melakukan apa-apa,
     * dan itu tidak jadi masalah: SQLite hanya mengizinkan satu penulis pada
     * satu waktu, jadi serialisasinya sudah dijamin mesinnya.
     */
    public function lockAll(): void
    {
        StorageProvider::withTrashed()
            ->lockForUpdate()
            ->get(['id']);
    }

    public function recordTest(
        StorageProvider $provider,
        string $status,
        string $message,
        int|float|null $durationMs = null
    ): StorageProvider {

        $provider->update([

            'last_tested_at' => now(),

            'last_test_status' => $status,

            // Pesan dari SDK penyimpanan bisa memuat seluruh jejak permintaan.
            // Dipotong supaya tidak melewati batas kolom TEXT.
            'last_test_message' => mb_substr($message, 0, 2000),

            // Dibulatkan ke milidetik bulat: kolomnya integer, dan pecahan
            // mikrodetik dari microtime() tidak berarti apa-apa bagi orang
            // yang membandingkan dua provider.
            'last_test_duration_ms' => $durationMs === null
                ? null
                : (int) round($durationMs),

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

    public function changedPriorities(array $priorities): array
    {
        if ($priorities === []) {
            return [];
        }

        // Satu query untuk seluruh id, bukan satu query per baris.
        $tersimpan = StorageProvider::withTrashed()
            ->whereIn('id', array_map('intval', array_keys($priorities)))
            ->pluck('priority', 'id');

        $berubah = [];

        foreach ($priorities as $id => $priority) {
            $id = (int) $id;

            // Id yang tidak ada di database dibuang tanpa suara. Ini bisa
            // terjadi wajar: admin membuka daftar, provider dihapus di tab
            // lain, lalu formulir lama tetap dikirim.
            if (! $tersimpan->has($id)) {
                continue;
            }

            if ((int) $tersimpan[$id] !== (int) $priority) {
                $berubah[$id] = (int) $priority;
            }
        }

        return $berubah;
    }

    public function countActive(): int
    {
        return StorageProvider::query()->active()->count();
    }
}
