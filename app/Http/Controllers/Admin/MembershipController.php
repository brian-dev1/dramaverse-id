<?php

namespace App\Http\Controllers\Admin;

use App\Models\MembershipPlan;

class MembershipController extends ResourceController
{
    protected function model(): string
    {
        return MembershipPlan::class;
    }

    protected function title(): string
    {
        return 'Kelola Paket Membership';
    }

    protected function routeKey(): string
    {
        return 'membership';
    }

    protected function columns(): array
    {
        return ['Nama' => 'name', 'Harga' => 'price', 'Durasi (hari)' => 'duration', 'Aktif' => 'is_active'];
    }

    protected function searchable(): array
    {
        return ['name'];
    }

    protected function relations(): array
    {
        return [];
    }
}
