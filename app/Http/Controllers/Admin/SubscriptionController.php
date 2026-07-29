<?php

namespace App\Http\Controllers\Admin;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Daftar baca-saja. Rute tambah/ubah/hapus belum didaftarkan untuk
 * modul ini, jadi rules() sengaja kosong.
 */
class SubscriptionController extends AdminCrudController
{
    protected function model(): string
    {
        return Subscription::class;
    }

    protected function routeKey(): string
    {
        return 'subscription';
    }

    protected function label(): string
    {
        return 'Langganan';
    }

    protected function columns(): array
    {
        return ['Pengguna' => 'user.name', 'Paket' => 'plan.name', 'Harga' => 'price', 'Status' => 'status', 'Mulai' => 'started_at', 'Berakhir' => 'expired_at'];
    }

    protected function sortable(): array
    {
        return ['price', 'started_at', 'expired_at'];
    }

    protected function searchable(): array
    {
        return ['payment_reference'];
    }

    protected function relations(): array
    {
        return ['user:id,name', 'plan:id,name'];
    }

    protected function filters(): array
    {
        return ['status' => ['label' => 'Status', 'options' => ['active' => 'Aktif', 'pending' => 'Menunggu', 'expired' => 'Kedaluwarsa', 'cancelled' => 'Dibatalkan']]];
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
