<?php

namespace App\Http\Controllers\Api;

use App\Models\Episode;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlayerProgressRequest;
use App\Services\ProgressService;

class ProgressController extends Controller
{
    public function __construct(
        protected ProgressService $progressService
    ) {
    }

    public function __invoke(PlayerProgressRequest $request)
    {
        $episode = Episode::findOrFail(
            $request->episode_id
        );

        $history = $this->progressService->update(
            $request->user(),
            $episode,
            $request->progress
        );

        return response()->json([
            'success' => true,
            'data' => [
                'episode_id' => $episode->id,
                'progress' => $history->progress,
                'updated_at' => $history->last_watched_at,
            ],
        ]);
    }
}