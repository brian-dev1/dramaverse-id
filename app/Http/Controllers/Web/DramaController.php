<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Drama;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class DramaController extends Controller
{
    public function __invoke(Drama $drama): View
    {
        abort_unless($drama->published_at !== null && $drama->published_at->isPast(), 404);

        $drama->load([
            'country:id,name,slug,flag_emoji',
            'genres:id,name,slug',
            'episodes' => fn ($q) => $q->select([
                'id', 'drama_id', 'episode_number', 'title',
                'thumbnail', 'is_vip', 'air_date',
            ]),
        ]);

        // Drama lain dengan genre yang bersinggungan.
        $related = Drama::query()
            ->select([
                'dramas.id', 'title', 'slug', 'poster', 'gradient', 'country_id',
                'total_episode', 'status', 'views', 'is_vip',
            ])
            ->with(['country:id,name,slug,flag_emoji'])
            ->published()
            ->whereKeyNot($drama->id)
            ->whereHas('genres', fn ($q) => $q->whereIn('genres.id', $drama->genres->pluck('id')))
            ->orderByDesc('views')
            ->take(12)
            ->get();

        $isFavorite = false;
        $inMyList   = false;

        if (Auth::check()) {
            $isFavorite = Auth::user()->favorites()->where('drama_id', $drama->id)->exists();
            $inMyList   = Auth::user()->watchlists()->where('drama_id', $drama->id)->exists();
        }

        $drama->increment('views');

        return view('web.pages.drama', compact('drama', 'related', 'isFavorite', 'inMyList'));
    }
}
