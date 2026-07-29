<?php

namespace Database\Seeders;

use App\Models\MembershipPlan;
use Illuminate\Database\Seeder;

class MembershipPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug'        => 'free',
                'name'        => 'Gratis',
                'price'       => 0,
                'duration'    => 3650,
                'sort_order'  => 1,
                'badge'       => null,
                'description' => 'Akses katalog non-VIP dengan iklan sesekali.',
                'benefits'    => ['Katalog non-VIP', 'Kualitas HD', 'Riwayat tontonan'],
            ],
            [
                'slug'        => 'vip',
                'name'        => 'VIP',
                'price'       => 39000,
                'duration'    => 30,
                'sort_order'  => 2,
                'badge'       => 'Populer',
                'description' => 'Seluruh katalog termasuk judul VIP, tanpa iklan, kualitas Full HD.',
                'benefits'    => ['Seluruh katalog', 'Tanpa iklan', 'Kualitas Full HD', 'Rilis lebih awal'],
            ],
            [
                'slug'        => 'premium',
                'name'        => 'Premium',
                'price'       => 99000,
                'duration'    => 90,
                'sort_order'  => 3,
                'badge'       => 'Terlengkap',
                'description' => 'Semua benefit VIP, ditambah kualitas 4K dan unduh offline.',
                'benefits'    => ['Semua benefit VIP', 'Kualitas 4K', 'Unduh offline', 'Dukungan prioritas'],
            ],
        ];

        foreach ($plans as $plan) {
            MembershipPlan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan + ['is_active' => true]
            );
        }
    }
}
