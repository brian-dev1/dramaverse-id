<?php

namespace App\Services;

use Illuminate\Http\Request;
use App\Repositories\Contracts\ActivityLogRepositoryInterface;

class ActivityLogService
{
    public function __construct(
        protected ActivityLogRepositoryInterface $repository
    ) {
    }

    public function log(
        Request $request,
        string $module,
        string $action,
        ?string $description = null,
        ?array $payload = null
    ) {

        return $this->repository->create([

            'user_id' => auth()->id(),

            'module' => $module,

            'action' => $action,

            'description' => $description,

            'ip_address' => $request->ip(),

            'user_agent' => $request->userAgent(),

            'payload' => $payload,

        ]);

    }

    public function latest()
    {
        return $this->repository->latest();
    }
}