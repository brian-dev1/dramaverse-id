<?php

namespace Database\Seeders;

use App\Models\Genre;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GenreSeeder extends Seeder
{
    public function run(): void
    {
        $genres = [
            'Romansa'  => 'Kisah cinta yang menghangatkan dan mematahkan hati.',
            'Sejarah'  => 'Latar kerajaan, perang, dan intrik masa lalu.',
            'Misteri'  => 'Teka-teki yang menuntut dipecahkan.',
            'Fantasi'  => 'Dunia di luar nalar dan hukum alam.',
            'Komedi'   => 'Ringan, hangat, dan mengundang tawa.',
            'Keluarga' => 'Hubungan antar generasi dan rumah yang ditinggali.',
            'Aksi'     => 'Kejar-kejaran, perkelahian, dan taruhan nyawa.',
            'Sekolah'  => 'Masa remaja, persahabatan, dan pertumbuhan.',
            'Thriller' => 'Ketegangan yang tidak memberi jeda.',
            'Medis'    => 'Ruang operasi dan keputusan hidup-mati.',
        ];

        $order = 0;

        foreach ($genres as $name => $description) {
            Genre::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name'        => $name,
                    'description' => $description,
                    'sort_order'  => ++$order,
                    'is_active'   => true,
                ]
            );
        }
    }
}
