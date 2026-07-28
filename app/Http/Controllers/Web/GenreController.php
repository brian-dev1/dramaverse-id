<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Genre;
use Illuminate\Contracts\View\View;

class GenreController extends Controller
{
    private const PER_PAGE = 24;

    /** Semua genre aktif beserta jumlah dramanya. */
    public function index(): View
    {
        $genres = Genre::active()
            ->withCount(['dramas' => fn ($q) => $q->published()])
            ->get();

        return view('web.pages.genre.index', compact('genres'));
    }

    /** Daftar drama pada satu genre. */
    public function show(Genre $genre): View
    {
        abort_unless($genre->is_active, 404);

        $dramas = $genre->dramas()
            ->select([
                'dramas.id', 'title', 'slug', 'poster', 'gradient', 'country_id',
                'release_year', 'total_episode', 'status', 'rating', 'views',
                'is_vip', 'published_at',
            ])
            ->with(['country:id,name,slug,flag_emoji'])
            ->published()
            ->latestRelease()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('web.pages.genre.show', compact('genre', 'dramas'));
    }
}
