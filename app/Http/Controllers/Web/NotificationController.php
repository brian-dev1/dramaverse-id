<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    public function index(): View
    {
        $notifications = Auth::user()
            ->notifications()
            ->latest()
            ->paginate(self::PER_PAGE);

        return view('web.pages.notifications', compact('notifications'));
    }

    public function markAllRead(): RedirectResponse
    {
        Auth::user()
            ->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('status', 'Semua notifikasi ditandai sudah dibaca.');
    }
}
