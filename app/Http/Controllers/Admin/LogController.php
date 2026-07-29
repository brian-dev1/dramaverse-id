<?php

namespace App\Http\Controllers\Admin;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Daftar baca-saja. Rute tambah/ubah/hapus belum didaftarkan untuk
 * modul ini, jadi rules() sengaja kosong.
 */
class LogController extends AdminCrudController
{
    protected function model(): string
    {
        return ActivityLog::class;
    }

    protected function routeKey(): string
    {
        return 'logs';
    }

    protected function label(): string
    {
        return 'Log Aktivitas';
    }

    protected function columns(): array
    {
        return ['Pengguna' => 'user.name', 'Modul' => 'module', 'Aksi' => 'action', 'Keterangan' => 'description', 'IP' => 'ip_address', 'Waktu' => 'created_at'];
    }

    protected function sortable(): array
    {
        return ['created_at', 'module', 'action'];
    }

    protected function searchable(): array
    {
        return ['action', 'module', 'description'];
    }

    protected function relations(): array
    {
        return ['user:id,name'];
    }

    protected function filters(): array
    {
        return ['module' => ['label' => 'Modul', 'options' => ['drama' => 'Drama', 'episode' => 'Episode', 'genre' => 'Genre', 'country' => 'Negara', 'banner' => 'Banner']]];
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
