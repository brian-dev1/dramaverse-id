<?php

namespace App\Http\Controllers\Api;

use App\Models\Episode;
use App\Http\Controllers\Controller;
use App\Services\PlayerService;
use Illuminate\Support\Facades\Auth;

class PlayerResumeController extends Controller
{
    public function __construct(
        protected PlayerService $playerService
    ) {
    }

    public function __invoke(Episode $episode)
    {
        $progress = $this->playerService->resume(
            Auth::user(),
            $episode
        );

        return response()->json([
            'success' => true,
            'data' => [
                'episode_id' => $episode->id,
                'progress' => $progress,
            ],
        ]);
    }
}