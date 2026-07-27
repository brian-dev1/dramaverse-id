<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Services\PlayerService;
use Illuminate\Support\Facades\Auth;

class EpisodeController extends Controller
{
    public function __construct(
        protected PlayerService $playerService
    ) {
    }

    public function __invoke(Episode $episode)
    {
        return view(

            'episode.show',

            $this->playerService->watch(

                $episode,

                Auth::user()

            )

        );
    }
}