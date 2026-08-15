<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Services\EpisodeAccessService;
use App\Services\PlayerService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class EpisodeController extends Controller
{
    public function __construct(
        protected PlayerService $playerService,
        protected EpisodeAccessService $episodeAccessService
    ) {
    }

    public function __invoke(Episode $episode): View
    {
        $episode->load(['drama:id,title,slug,poster,cover,gradient,total_episode']);

        abort_unless((bool) $episode->drama, 404);

        // Episode VIP hanya untuk anggota yang langganannya aktif (atau admin).
        // Memakai EpisodeAccessService — sumber kebenaran yang sama dipakai
        // bot Telegram — supaya aturan "boleh menonton atau tidak" tidak
        // didefinisikan ulang secara terpisah dan berisiko berbeda/rusak.
        if (! $this->episodeAccessService->canWatch(Auth::user(), $episode)) {
            abort(403, 'Part ini khusus anggota VIP.');
        }

        $data = $this->playerService->watch($episode, Auth::user());

        $data['drama']    = $episode->drama;
        $data['episode']  = $episode;
        $data['episodes'] = $episode->drama
            ->episodes()
            ->select(['id', 'drama_id', 'episode_number', 'title', 'is_vip'])
            ->get();

        $episode->increment('views');

        return view('web.pages.episode', $data);
    }
}