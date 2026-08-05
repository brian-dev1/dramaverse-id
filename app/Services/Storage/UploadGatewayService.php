<?php

namespace App\Services\Storage;

use App\Models\StorageProvider;
use App\Services\Storage\Contracts\StorageManagerInterface;

/**
 * Upload Gateway.
 *
 * Seluruh worker eksternal (Telegram Downloader, importer, dsb.)
 * nantinya meminta tujuan upload melalui service ini.
 *
 * Pada Phase 10.1 service ini BELUM melakukan upload.
 * Tugasnya hanya menentukan storage provider yang harus dipakai.
 *
 * Dengan begitu worker tidak lagi mengetahui Cloudflare R2,
 * Wasabi, Backblaze ataupun provider lain secara langsung.
 *
 * Semua keputusan berada di Laravel.
 */
class UploadGatewayService
{
    public function __construct(
        protected StorageManagerInterface $storage,
    ) {
    }

    /**
     * Mengembalikan provider tujuan upload.
     *
     * Saat ini hanya memakai default provider
     * sehingga seluruh sistem tetap berjalan
     * persis seperti sebelumnya.
     */
    public function uploadProvider(): StorageProvider
    {
        return $this->storage->defaultProvider()
            ?? throw new \RuntimeException(
                'Tidak ada storage provider yang tersedia.'
            );
    }
}