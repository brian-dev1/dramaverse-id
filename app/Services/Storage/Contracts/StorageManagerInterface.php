<?php

namespace App\Services\Storage\Contracts;

use App\Models\StorageProvider;
use App\Services\Storage\StorageTestResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;

/**
 * Pintu masuk tunggal ke semua storage provider.
 *
 * Kode lain TIDAK boleh memanggil Storage::disk('s3') langsung. Seluruh
 * akses berkas lewat kontrak ini, supaya penambahan atau pemindahan provider
 * cukup mengubah baris database dan tidak menyentuh kode pemanggil.
 */
interface StorageManagerInterface
{
    /**
     * Disk untuk satu provider. Tanpa argumen: disk provider default.
     */
    public function disk(?string $slug = null): Filesystem;

    /**
     * Disk provider default.
     */
    public function default(): Filesystem;

    /**
     * Baris provider. Tanpa argumen: provider default.
     */
    public function provider(?string $slug = null): StorageProvider;

    /**
     * Provider default, atau null bila belum ada.
     */
    public function defaultProvider(): ?StorageProvider;

    /**
     * Semua provider yang siap dipakai, urut prioritas (kecil lebih dulu).
     *
     * @return Collection<int, StorageProvider>
     */
    public function usableProviders(): Collection;

    /**
     * Urutan percobaan: provider default lebih dulu, lalu sisanya menurut
     * prioritas. Dipakai router upload nanti untuk failover.
     *
     * @return Collection<int, StorageProvider>
     */
    public function chain(): Collection;

    /**
     * Coba hubungi provider: tulis berkas uji, baca ulang, hapus.
     * Tidak pernah melempar exception — kegagalan dikembalikan sebagai hasil.
     */
    public function test(StorageProvider $provider): StorageTestResult;

    /**
     * Buang disk yang sudah dibangun dari memoisasi. Wajib dipanggil setelah
     * konfigurasi provider berubah dalam permintaan yang sama.
     */
    public function forget(?string $slug = null): void;
}
