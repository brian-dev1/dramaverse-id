<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\Drama;
use Illuminate\Database\Seeder;

class BannerSeeder extends Seeder
{
    public function run(): void
    {
        $featured = Drama::query()
            ->where('is_featured', true)
            ->orderByDesc('rating')
            ->take(5)
            ->get();

        foreach ($featured as $i => $drama) {
            Banner::updateOrCreate(
                ['title' => $drama->title],
                [
                    'subtitle'    => $drama->synopsis,
                    'image'       => $drama->cover ?? '',
                    // Simpan path relatif, bukan URL penuh: seeder tidak boleh
                    // bergantung pada route cache, dan tautan harus tetap benar
                    // walau domain berubah.
                    'link'        => '/drama/'.$drama->slug,
                    'button_text' => 'Tonton Sekarang',
                    'position'    => 'hero',
                    'sort_order'  => $i + 1,
                    'is_active'   => true,
                ]
            );
        }
    }
}
