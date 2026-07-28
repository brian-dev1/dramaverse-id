<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;

class UserController extends ResourceController
{
    protected function model(): string
    {
        return User::class;
    }

    protected function title(): string
    {
        return 'Kelola Pengguna';
    }

    protected function routeKey(): string
    {
        return 'user';
    }

    protected function columns(): array
    {
        return ['Nama' => 'name', 'Telegram' => 'telegram_username', 'Aktif' => 'is_active', 'Diblokir' => 'is_banned', 'Bergabung' => 'created_at'];
    }

    protected function searchable(): array
    {
        return ['name', 'telegram_username'];
    }

    protected function relations(): array
    {
        return [];
    }
}
