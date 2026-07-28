<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * Pengguna default adalah pengguna Telegram — tanpa email dan kata sandi.
     */
    public function definition(): array
    {
        $first = fake()->firstName();
        $last  = fake()->lastName();

        return [
            'name'                => "{$first} {$last}",
            'telegram_id'         => fake()->unique()->numberBetween(100_000_000, 999_999_999),
            'telegram_username'   => Str::lower($first).'_'.fake()->unique()->numberBetween(10, 9999),
            'telegram_first_name' => $first,
            'telegram_last_name'  => $last,
            'telegram_language'   => 'id',
            'email'               => null,
            'password'            => null,
            'is_admin'            => false,
            'is_active'           => true,
            'is_banned'           => false,
            'remember_token'      => Str::random(10),
        ];
    }

    /** Akun admin: memakai email + kata sandi. */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'telegram_id'       => null,
            'telegram_username' => null,
            'email'             => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => static::$password ??= Hash::make('password'),
            'is_admin'          => true,
        ]);
    }

    /** Akun yang diblokir. */
    public function banned(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_banned' => true,
            'is_active' => false,
        ]);
    }
}
