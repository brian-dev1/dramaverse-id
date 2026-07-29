<?php

namespace Database\Seeders;

use App\Enums\StorageDriver;
use App\Enums\StorageStatus;
use App\Models\StorageProvider;
use Illuminate\Database\Seeder;

/**
 * Seeder produksi untuk storage provider.
 *
 * Hanya memasang SATU provider: penyimpanan lokal.
 *
 * Provider awan tidak ikut di-seed, dan itu keputusan yang disengaja. Baris
 * R2 atau Wasabi tanpa kredensial sungguhan hanya akan tampak seperti
 * provider yang siap dipakai padahal tidak — persis jenis data karangan yang
 * dihindari proyek ini. Lebih buruk lagi, kredensial contoh yang ikut
 * ter-commit adalah masalah keamanan.
 *
 * Provider awan ditambahkan lewat panel admin, oleh orang yang memang
 * memegang kuncinya.
 */
class StorageProviderSeeder extends Seeder
{
    public function run(): void
    {
        $local = StorageProvider::where('slug', 'local')->first();

        $attributes = [

            'name' => 'Penyimpanan Lokal',

            'driver' => StorageDriver::LOCAL->value,

            // Menunjuk ke direktori yang sudah ditautkan `storage:link`,
            // sehingga berkasnya benar-benar bisa diakses lewat /storage.
            'root' => storage_path('app/public'),

            'public_url' => rtrim((string) config('app.url'), '/').'/storage',

            'visibility' => 'public',

            'status' => StorageStatus::ACTIVE->value,

            // Angka besar: lokal adalah pilihan terakhir. Disk VPS tidak
            // dimaksudkan menampung berkas video.
            'priority' => 900,

        ];

        if ($local === null) {

            // Provider pertama harus menjadi default, kalau tidak sistem
            // berdiri tanpa tujuan penyimpanan sama sekali.
            $attributes['is_default'] = StorageProvider::query()->isDefault()->doesntExist();

            StorageProvider::create($attributes + ['slug' => 'local']);

            return;
        }

        // Sudah ada. Kolom is_default, priority, dan status TIDAK disentuh:
        // itu keputusan operator, dan seeder yang dijalankan ulang saat
        // deploy tidak boleh membatalkannya.
        $local->update([
            'name'       => $attributes['name'],
            'driver'     => $attributes['driver'],
            'public_url' => $attributes['public_url'],
        ]);
    }
}
