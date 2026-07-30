<?php

namespace App\Services\Storage\Exceptions;

use App\Models\StorageProvider;
use RuntimeException;

/**
 * Kegagalan yang berkaitan dengan konfigurasi storage provider.
 *
 * Setiap pesan dibuat menyebut nama provider dan langkah perbaikannya.
 * Storage provider salah konfigurasi biasanya baru terasa jauh dari
 * penyebabnya — pesan yang samar membuat penelusurannya mahal.
 */
class StorageProviderException extends RuntimeException
{
    public static function noneAvailable(): self
    {
        return new self(
            'Tidak ada storage provider yang aktif dan lengkap. '
            .'Aktifkan minimal satu provider, atau jalankan '
            .'`php artisan db:seed --class=Database\\Seeders\\StorageProviderSeeder` '
            .'untuk memasang provider lokal.'
        );
    }

    public static function notFound(string $slug): self
    {
        return new self(
            "Storage provider `{$slug}` tidak ada di tabel storage_providers."
        );
    }

    public static function noDefault(): self
    {
        return new self(
            'Belum ada storage provider bawaan. Tandai satu provider aktif '
            .'sebagai default sebelum berkas bisa disimpan.'
        );
    }

    public static function inactive(StorageProvider $provider): self
    {
        return new self(
            "Storage provider `{$provider->slug}` berstatus nonaktif."
        );
    }

    public static function incomplete(StorageProvider $provider): self
    {
        $fields = implode(', ', $provider->missingFields());

        return new self(
            "Storage provider `{$provider->slug}` ({$provider->driver->label()}) "
            ."belum lengkap. Field yang masih kosong: {$fields}."
        );
    }

    /**
     * Adapter Flysystem belum terpasang.
     *
     * Ini kelas kesalahan yang tidak bisa ditangkap pemeriksaan statis:
     * kodenya benar, konfigurasinya benar, tapi vendor/ tidak memuat
     * adapternya. Pesannya menyebut perintah composer yang harus dijalankan.
     */
    public static function adapterMissing(StorageProvider $provider): self
    {
        $package = $provider->driver->composerPackage();

        return new self(
            "Adapter untuk `{$provider->driver->label()}` belum terpasang. "
            ."Jalankan: composer require {$package}"
        );
    }

    /**
     * Nilai contoh masih tertinggal.
     */
    public static function hasPlaceholders(StorageProvider $provider): self
    {
        $parts = [];

        foreach ($provider->placeholderFields() as $field => $token) {
            $parts[] = "{$field} (masih memuat \"{$token}\")";
        }

        return new self(
            "Storage provider `{$provider->slug}` masih memuat nilai contoh: "
            .implode(', ', $parts).'. Ganti dengan nilai sungguhan dulu.'
        );
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self(
            "Driver storage `{$driver}` tidak dikenali."
        );
    }

    public static function cannotDeleteDefault(StorageProvider $provider): self
    {
        return new self(
            "Provider `{$provider->slug}` sedang menjadi default dan tidak bisa "
            .'dihapus. Tandai provider lain sebagai default terlebih dahulu.'
        );
    }

    public static function cannotDefaultInactive(StorageProvider $provider): self
    {
        return new self(
            "Provider `{$provider->slug}` harus aktif sebelum bisa dijadikan default."
        );
    }

    public static function cannotDisableDefault(StorageProvider $provider): self
    {
        return new self(
            "Provider `{$provider->slug}` sedang menjadi default. Pindahkan status "
            .'default ke provider lain sebelum menonaktifkannya.'
        );
    }
}
