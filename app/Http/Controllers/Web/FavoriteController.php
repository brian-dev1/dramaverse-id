<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Drama;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class FavoriteController extends Controller
{
    private const PER_PAGE = 24;

    public function index(): View
    {
        $dramas = Drama::query()
            ->select([
                'dramas.id', 'title', 'slug', 'poster', 'gradient', 'country_id',
                'release_year', 'total_episode', 'status', 'rating', 'views', 'is_vip',
            ])
            ->with(['country:id,name,slug,flag_emoji'])
            ->join('favorites', 'favorites.drama_id', '=', 'dramas.id')
            ->where('favorites.user_id', Auth::id())
            ->orderByDesc('favorites.created_at')
            ->paginate(self::PER_PAGE);

        return view('web.pages.favorites', compact('dramas'));
    }

    /** Tambah/hapus favorit. */
    public function toggle(Drama $drama): RedirectResponse
    {
        $favorite = Auth::user()->favorites()->where('drama_id', $drama->id)->first();

        if ($favorite) {
            $favorite->delete();
            $message = 'Dihapus dari favorit.';
        } else {
            Auth::user()->favorites()->create(['drama_id' => $drama->id]);
            $message = 'Ditambahkan ke favorit.';
        }

        return back()->with('status', $message);
    }
}
