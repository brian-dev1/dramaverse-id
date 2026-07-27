<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Services\ActivityLogService;

class ActivityLogController extends Controller
{
    public function __construct(
        protected ActivityLogService $service
    ) {
    }

    public function index()
    {
        return ActivityLogResource::collection(
            $this->service->latest()
        );
    }
}