<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Daftar baca-saja. Rute tambah/ubah/hapus belum didaftarkan untuk
 * modul ini, jadi rules() sengaja kosong.
 */
class UserController extends AdminCrudController
{
    protected function model(): string
    {
        return User::class;
    }

    protected function routeKey(): string
    {
        return 'user';
    }

    protected function label(): string
    {
        return 'Pengguna';
    }

    protected function columns(): array
    {
        return ['Nama' => 'name', 'Telegram' => 'telegram_username', 'Aktif' => 'is_active', 'Diblokir' => 'is_banned', 'Terakhir masuk' => 'last_login_at', 'Bergabung' => 'created_at'];
    }

    protected function sortable(): array
    {
        return ['name', 'last_login_at', 'created_at'];
    }

    protected function searchable(): array
    {
        return ['name', 'telegram_username', 'email'];
    }

    protected function relations(): array
    {
        return [];
    }

    protected function filters(): array
    {
        return ['is_active' => ['label' => 'Status', 'options' => [1 => 'Aktif', 0 => 'Nonaktif']], 'is_banned' => ['label' => 'Blokir', 'options' => [1 => 'Diblokir', 0 => 'Normal']]];
    }

    protected function bulkActions(): array
    {
        return [];
    }

    protected function rules(Request $request, ?Model $model = null): array
    {
        return [];
    }
}
