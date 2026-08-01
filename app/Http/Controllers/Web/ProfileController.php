<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();

        $stats = [
            'history'   => $user->watchHistories()->count(),
            'favorites' => $user->favorites()->count(),
            'myList'    => $user->watchlists()->count(),
        ];

        $continueWatching = $user->watchHistories()
            ->with(['drama:id,title,slug,poster,gradient,total_episode', 'episode:id,drama_id,episode_number,duration'])
            ->where('completed', false)
            ->whereHas('drama')
            ->orderByDesc('last_watched_at')
            ->take(6)
            ->get();

        $subscription = $user->subscriptions()
            ->with('plan')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>', now()))
            ->latest()
            ->first();

        return view('web.pages.profile', compact('user', 'stats', 'continueWatching', 'subscription'));
    }

    /** Keluar dari sesi dan kembali ke beranda. */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('web.home')->with('status', 'Anda telah keluar.');
    }
}

