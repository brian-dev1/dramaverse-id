<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminService;

class DashboardController extends Controller
{
    public function __construct(
        protected AdminService $service
    ) {
    }

    public function __invoke()
    {
        return response()->json([

            'success' => true,

            'data' => $this->service->dashboard(),

        ]);
    }
}