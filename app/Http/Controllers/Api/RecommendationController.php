<?php

namespace App\Http\Controllers\Api;

use App\Models\Drama;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecommendationResource;
use App\Services\RecommendationService;

class RecommendationController extends Controller
{
    public function __construct(
        protected RecommendationService $service
    ) {
    }

    public function index()
    {
        return response()->json([

            'success' => true,

            'data' => RecommendationResource::collection(

                $this->service->recommended(
                    auth()->user()
                )

            ),

        ]);
    }

    public function because(
        Drama $drama
    ) {

        return response()->json([

            'success' => true,

            'data' => RecommendationResource::collection(

                $this->service->becauseYouWatched(
                    $drama
                )

            ),

        ]);

    }

    public function trending()
    {
        return response()->json([

            'success' => true,

            'data' => RecommendationResource::collection(

                $this->service->trending()

            ),

        ]);
    }
}