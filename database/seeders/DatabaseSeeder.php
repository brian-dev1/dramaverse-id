<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Urutan penting: taksonomi -> katalog -> turunannya.
     */
    public function run(): void
    {
        $this->call([
            GenreSeeder::class,
            CountrySeeder::class,
            MembershipPlanSeeder::class,
            DramaSeeder::class,
            BannerSeeder::class,
            UserSeeder::class,
            WatchHistorySeeder::class,
        ]);
    }
}
