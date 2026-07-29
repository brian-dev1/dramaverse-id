<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Akun admin awal — satu-satunya akun yang memakai email + kata sandi.
 * Pengguna biasa masuk lewat Telegram dan dibuat otomatis oleh bot.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_SEED_EMAIL', 'admin@dramaverse.id');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'      => 'Administrator',
                'password'  => Hash::make(env('ADMIN_SEED_PASSWORD', 'dramaverse')),
                'is_admin'  => true,
                'is_active' => true,
            ]
        );

        $this->command?->info("Akun admin siap: {$email}");
        $this->command?->warn('Segera ganti kata sandinya: php artisan admin:password '.$email);
    }
}
