<?php

namespace App\Http\Controllers\Admin;

use App\Models\MembershipPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Daftar baca-saja. Rute tambah/ubah/hapus belum didaftarkan untuk
 * modul ini, jadi rules() sengaja kosong.
 */
class MembershipController extends AdminCrudController
{
    protected function model(): string
    {
        return MembershipPlan::class;
    }

    protected function routeKey(): string
    {
        return 'membership';
    }

    protected function label(): string
    {
        return 'Paket Membership';
    }

    protected function columns(): array
    {
        return ['Nama' => 'name', 'Harga' => 'price', 'Durasi (hari)' => 'duration', 'Aktif' => 'is_active'];
    }

    protected function sortable(): array
    {
        return ['name', 'price', 'duration'];
    }

    protected function searchable(): array
    {
        return ['name'];
    }

    protected function relations(): array
    {
        return [];
    }

    protected function filters(): array
    {
        return ['is_active' => ['label' => 'Status', 'options' => [1 => 'Aktif', 0 => 'Nonaktif']]];
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
