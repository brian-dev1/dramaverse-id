<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class HistoryController extends Controller
{
    private const PER_PAGE = 20;

    /** Seluruh riwayat tontonan. */
    public function index(): View
    {
        $histories = Auth::user()
            ->watchHistories()
            ->with([
                'drama:id,title,slug,poster,gradient,total_episode',
                'episode:id,drama_id,episode_number,title,duration',
            ])
            ->whereHas('drama')
            ->orderByDesc('last_watched_at')
            ->paginate(self::PER_PAGE);

        return view('web.pages.history', [
            'histories' => $histories,
            'title'     => 'Riwayat Tontonan',
        ]);
    }

    /** Hanya yang belum selesai — untuk /continue-watching. */
    public function continueWatching(): View
    {
        $histories = Auth::user()
            ->watchHistories()
            ->with([
                'drama:id,title,slug,poster,gradient,total_episode',
                'episode:id,drama_id,episode_number,title,duration',
            ])
            ->where('completed', false)
            ->whereHas('drama')
            ->orderByDesc('last_watched_at')
            ->paginate(self::PER_PAGE);

        return view('web.pages.history', [
            'histories' => $histories,
            'title'     => 'Lanjutkan Menonton',
        ]);
    }
}
