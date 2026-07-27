<?php

namespace App\Http\Controllers\Api;

use App\Models\Episode;
use App\Http\Controllers\Controller;
use App\Services\PlayerService;
use Illuminate\Support\Facades\Auth;

class PlayerCompletedController extends Controller
{
    public function __construct(
        protected PlayerService $playerService
    ) {
    }

    public function __invoke(Episode $episode)
    {
        $user = Auth::user();

        abort_unless($user, 401);

        $this->playerService->complete(
            $user,
            $episode
        );

        return response()->json([

            'success' => true,

            'message' => 'Episode completed.',

            'episode_id' => $episode->id,

        ]);
    }
}