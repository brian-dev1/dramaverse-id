<?php

namespace App\Http\Controllers\Api;

use App\Models\Episode;
use App\Http\Controllers\Controller;
use App\Services\EpisodeAccessService;

class EpisodeAccessController extends Controller
{
    public function __construct(
        protected EpisodeAccessService $service
    ) {
    }

    public function __invoke(Episode $episode)
    {
        return response()->json([

            'success' => true,

            'can_watch' => $this->service->canWatch(
                auth()->user(),
                $episode
            ),

        ]);
    }
}