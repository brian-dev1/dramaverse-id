<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeder produksi.
 *
 * Hanya berisi data referensi yang memang harus ada agar aplikasi bisa
 * dipakai: taksonomi genre, daftar negara, paket membership, dan satu
 * akun admin.
 *
 * Katalog drama TIDAK diisi di sini. Situs sengaja dibiarkan kosong
 * sampai admin memasukkan judul yang sebenarnya — lebih baik halaman
 * kosong yang jujur daripada katalog berisi judul karangan.
 *
 * Untuk data contoh saat pengembangan:
 *   php artisan db:seed --class=Database\\Seeders\\Demo\\DemoSeeder
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            GenreSeeder::class,
            CountrySeeder::class,
            MembershipPlanSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
