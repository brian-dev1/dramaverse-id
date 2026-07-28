<?php

namespace App\Http\Controllers\Admin;

use App\Models\ActivityLog;

class LogController extends ResourceController
{
    protected function model(): string
    {
        return ActivityLog::class;
    }

    protected function title(): string
    {
        return 'Log Aktivitas';
    }

    protected function routeKey(): string
    {
        return 'logs';
    }

    protected function columns(): array
    {
        return ['Pengguna' => 'user.name', 'Aksi' => 'action', 'Waktu' => 'created_at'];
    }

    protected function searchable(): array
    {
        return ['action'];
    }

    protected function relations(): array
    {
        return ['user:id,name'];
    }
}
