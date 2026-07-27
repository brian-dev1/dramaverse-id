<?php

namespace App\Repositories;

use App\Models\ActivityLog;
use App\Repositories\Contracts\ActivityLogRepositoryInterface;

class ActivityLogRepository implements ActivityLogRepositoryInterface
{
    public function create(array $data)
    {
        return ActivityLog::create($data);
    }

    public function latest()
    {
        return ActivityLog::latest()->paginate(30);
    }

    public function byModule(string $module)
    {
        return ActivityLog::where(
            'module',
            $module
        )->latest()->paginate(30);
    }

    public function byUser(int $userId)
    {
        return ActivityLog::where(
            'user_id',
            $userId
        )->latest()->paginate(30);
    }
}