<?php

namespace App\Rules;

use App\Models\StorageProvider;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Provider tujuan harus benar-benar bisa menerima berkas.
 *
 * Dipisahkan sebagai Rule, bukan ditulis sebagai closure di satu FormRequest,
 * karena sprint subtitle dan thumbnail akan memerlukan pemeriksaan yang sama.
 * Menuliskannya ulang di sana berarti dua daftar syarat yang harus diingat
 * bersamaan — dan yang satu akan tertinggal saat syaratnya berubah.
 *
 * Pesan galatnya sengaja menyebut sebab yang tepat, bukan "provider tidak
 * valid". Admin yang melihat pilihannya ditolak perlu tahu apakah ia harus
 * mengaktifkan provider, mengisi kredensial, memasang paket composer, atau
 * menjalankan Test Connection.
 */
class UsableStorageProvider implements ValidationRule
{
    public function __construct(
        /**
         * Wajibkan provider sudah lolos Test Connection.
         *
         * Untuk video episode ini `true`: berkasnya besar, dan mengetahui
         * providernya tidak bisa dihubungi SETELAH menunggu unggahan berjam-jam
         * adalah kegagalan yang paling mahal di seluruh alur ini.
         */
        protected bool $requireTested = true,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $provider = StorageProvider::find($value);

        if ($provider === null) {
            $fail('Storage provider itu tidak ditemukan. Muat ulang halaman — '
                .'mungkin baru dihapus dari Storage Manager.');

            return;
        }

        if (! $provider->isActive()) {
            $fail("Storage provider \"{$provider->name}\" berstatus nonaktif. "
                .'Aktifkan dulu di Storage Manager.');

            return;
        }

        if (! $provider->hasAdapterInstalled()) {
            $fail("Adapter untuk {$provider->driver->label()} belum terpasang di "
                .'server. Jalankan: composer require '
                .$provider->driver->composerPackage());

            return;
        }

        if (! $provider->isConfigured()) {
            $fail("Storage provider \"{$provider->name}\" belum lengkap. Field "
                .'yang masih kosong: '.implode(', ', $provider->missingFields()));

            return;
        }

        if ($provider->hasPlaceholders()) {
            $fail("Storage provider \"{$provider->name}\" masih memuat nilai "
                .'contoh pada: '
                .implode(', ', array_keys($provider->placeholderFields())));

            return;
        }

        if ($this->requireTested && $provider->last_test_status !== 'ok') {
            $fail("Storage provider \"{$provider->name}\" belum pernah lolos "
                .'Test Connection. Uji dulu dari Storage Manager, atau '
                ."jalankan: php artisan storage:test {$provider->slug}");
        }
    }
}
