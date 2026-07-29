<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Pengguna Telegram contoh — hanya untuk pengembangan.
 * Di produksi, pengguna dibuat otomatis oleh bot saat mengirim /start.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
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
