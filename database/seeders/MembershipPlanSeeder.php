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
                'name'        => 'Gratis',
                'price'       => 0,
                'duration'    => 3650,
                'description' => 'Akses katalog non-VIP dengan iklan sesekali.',
            ],
            [
                'name'        => 'VIP',
                'price'       => 39000,
                'duration'    => 30,
                'description' => 'Seluruh katalog termasuk judul VIP, tanpa iklan, kualitas Full HD.',
            ],
            [
                'name'        => 'Premium',
                'price'       => 99000,
                'duration'    => 90,
                'description' => 'Semua benefit VIP, ditambah kualitas 4K, unduh offline, dan rilis lebih awal.',
            ],
        ];

        foreach ($plans as $plan) {
            MembershipPlan::updateOrCreate(
                ['name' => $plan['name']],
                $plan + ['is_active' => true]
            );
        }
    }
}
