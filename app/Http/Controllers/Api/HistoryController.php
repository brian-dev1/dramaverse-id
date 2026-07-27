<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HistoryResource;
use App\Services\WatchHistoryService;

class HistoryController extends Controller
{
    public function __construct(
        protected WatchHistoryService $service
    ) {
    }

    public function __invoke()
    {
        return response()->json([

            'success' => true,

            'data' => HistoryResource::collection(

                $this->service->history(
                    auth()->user()
                )

            ),

        ]);
    }
}