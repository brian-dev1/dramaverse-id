<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $service
    ) {
    }

    public function __invoke()
    {
        return response()->json([

            'success' => true,

            'data' => $this->service->dashboard(
                auth()->user()
            ),

        ]);
    }
}