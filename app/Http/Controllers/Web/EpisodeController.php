<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Services\PlayerService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class EpisodeController extends Controller
{
    public function __construct(
        protected PlayerService $playerService
    ) {
    }

    public function __invoke(Episode $episode): View
    {
        $episode->load(['drama:id,title,slug,poster,gradient,total_episode']);

        abort_unless((bool) $episode->drama, 404);

        // Episode VIP hanya untuk anggota yang berlangganan.
        if ($episode->is_vip && ! $this->hasVipAccess()) {
            abort(403, 'Episode ini khusus anggota VIP.');
        }

        $data = $this->playerService->watch($episode, Auth::user());

        $data['drama']    = $episode->drama;
        $data['episode']  = $episode;
        $data['episodes'] = $episode->drama
            ->episodes()
            ->select(['id', 'drama_id', 'episode_number', 'title', 'duration', 'is_vip'])
            ->get();

        $episode->increment('views');

        return view('web.pages.episode', $data);
    }

    private function hasVipAccess(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return $user->subscriptions()
            ->where('status', 'active')
            ->where('expired_at', '>', now())
            ->exists();
    }
}
