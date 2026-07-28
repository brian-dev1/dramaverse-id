<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Drama;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class MyListController extends Controller
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
            ->join('watchlists', 'watchlists.drama_id', '=', 'dramas.id')
            ->where('watchlists.user_id', Auth::id())
            ->orderByDesc('watchlists.created_at')
            ->paginate(self::PER_PAGE);

        return view('web.pages.my-list', compact('dramas'));
    }

    public function toggle(Drama $drama): RedirectResponse
    {
        $item = Auth::user()->watchlists()->where('drama_id', $drama->id)->first();

        if ($item) {
            $item->delete();
            $message = 'Dihapus dari daftar saya.';
        } else {
            Auth::user()->watchlists()->create([
                'drama_id' => $drama->id,
                'status'   => 'plan_to_watch',
            ]);
            $message = 'Ditambahkan ke daftar saya.';
        }

        return back()->with('status', $message);
    }
}
