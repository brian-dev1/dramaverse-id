<?php

namespace App\Http\Controllers\Admin;

use App\Models\Subscription;

class SubscriptionController extends ResourceController
{
    protected function model(): string
    {
        return Subscription::class;
    }

    protected function title(): string
    {
        return 'Kelola Langganan';
    }

    protected function routeKey(): string
    {
        return 'subscription';
    }

    protected function columns(): array
    {
        return ['Pengguna' => 'user.name', 'Paket' => 'plan.name', 'Harga' => 'price', 'Status' => 'status', 'Berakhir' => 'expired_at'];
    }

    protected function searchable(): array
    {
        return ['payment_reference'];
    }

    protected function relations(): array
    {
        return ['user:id,name', 'plan:id,name'];
    }
}
