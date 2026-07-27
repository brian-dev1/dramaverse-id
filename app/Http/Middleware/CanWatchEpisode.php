<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Episode;
use App\Services\EpisodeAccessService;

class CanWatchEpisode
{
    public function __construct(
        protected EpisodeAccessService $service
    ) {
    }

    public function handle($request, Closure $next)
    {
        $episode = $request->route('episode');

        if (!$episode instanceof Episode) {
            return response()->json([
                'success' => false,
                'message' => 'Episode not found.'
            ],404);
        }

        if (!$this->service->canWatch(
            auth()->user(),
            $episode
        )) {

            return response()->json([
                'success'=>false,
                'message'=>'Episode is locked.'
            ],403);

        }

        return $next($request);
    }
}