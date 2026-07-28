<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Mengganti kata sandi akun admin tanpa menyentuh database secara manual.
 */
class AdminPassword extends Command
{
    protected $signature = 'admin:password
                            {email? : Email akun admin}';

    protected $description = 'Ganti kata sandi akun admin';

    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Email admin');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("Akun dengan email {$email} tidak ditemukan.");

            $admins = User::where('is_admin', true)->pluck('email')->filter();

            if ($admins->isNotEmpty()) {
                $this->line('Akun admin yang tersedia:');
                $admins->each(fn ($e) => $this->line("  - {$e}"));
            }

            return self::FAILURE;
        }

        if (! $user->is_admin) {
            $this->error("Akun {$email} bukan admin.");

            return self::FAILURE;
        }

        $password = $this->secret('Kata sandi baru');
        $confirm  = $this->secret('Ulangi kata sandi');

        if ($password !== $confirm) {
            $this->error('Kata sandi tidak sama.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', Password::min(10)->letters()->numbers()->symbols()]]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user->forceFill(['password' => Hash::make($password)])->save();

        $this->info("Kata sandi untuk {$email} berhasil diperbarui.");

        return self::SUCCESS;
    }
}
