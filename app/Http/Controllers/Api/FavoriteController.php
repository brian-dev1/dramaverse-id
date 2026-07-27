<?php

namespace App\Http\Controllers\Api;

use App\Models\Drama;
use App\Http\Controllers\Controller;
use App\Http\Resources\FavoriteResource;
use App\Services\FavoriteService;

class FavoriteController extends Controller
{
    public function __construct(
        protected FavoriteService $service
    ) {
    }

    public function index()
    {
        return response()->json([

            'success' => true,

            'data' => FavoriteResource::collection(

                $this->service->all(
                    auth()->user()
                )

            ),

        ]);
    }

    public function store(
        Drama $drama
    ) {

        return response()->json([

            'success' => true,

            'data' => new FavoriteResource(

                $this->service->store(

                    auth()->user(),

                    $drama

                )

            ),

        ]);

    }

    public function destroy(
        Drama $drama
    ) {

        $this->service->delete(

            auth()->user(),

            $drama

        );

        return response()->json([

            'success' => true,

            'message' => 'Favorite removed.',

        ]);
    }
}