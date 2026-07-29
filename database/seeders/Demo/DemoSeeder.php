<?php

namespace Database\Seeders\Demo;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Data contoh untuk pengembangan.
 *
 * TIDAK dipanggil oleh DatabaseSeeder dan tidak boleh dijalankan di
 * produksi — isinya judul drama karangan.
 *
 * Cara pakai:
 *   php artisan db:seed --class=Database\\Seeders\\Demo\\DemoSeeder
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoSeeder tidak boleh dijalankan di produksi.');

            return;
        }

        $this->call([
            DramaSeeder::class,
            BannerSeeder::class,
            UserSeeder::class,
            WatchHistorySeeder::class,
        ]);

        $this->command?->info('Data contoh terpasang. Jangan pakai di produksi.');
    }
}
