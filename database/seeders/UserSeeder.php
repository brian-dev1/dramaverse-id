<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // --- Admin: satu-satunya akun yang memakai email + kata sandi ---
        User::updateOrCreate(
            ['email' => 'admin@dramaverse.id'],
            [
                'name'      => 'Administrator',
                'password'  => Hash::make(env('ADMIN_SEED_PASSWORD', 'dramaverse')),
                'is_admin'  => true,
                'is_active' => true,
            ]
        );

        // --- Pengguna contoh via Telegram (tanpa email dan kata sandi) ---
        $samples = [
            [700_100_001, 'rizky_pratama',  'Rizky',  'Pratama'],
            [700_100_002, 'siti_nurhaliza', 'Siti',   'Nurhaliza'],
            [700_100_003, 'bagas_w',        'Bagas',  'Wicaksono'],
        ];

        foreach ($samples as [$id, $username, $first, $last]) {
            User::updateOrCreate(
                ['telegram_id' => $id],
                [
                    'name'                => trim("{$first} {$last}"),
                    'telegram_username'   => $username,
                    'telegram_first_name' => $first,
                    'telegram_last_name'  => $last,
                    'telegram_language'   => 'id',
                    'is_admin'            => false,
                    'is_active'           => true,
                    'last_login_at'       => now()->subDays(random_int(0, 14)),
                ]
            );
        }
    }
}
