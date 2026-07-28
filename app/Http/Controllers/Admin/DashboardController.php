<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Drama;
use App\Models\Episode;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WatchHistory;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $stats = [
            'dramas'    => Drama::count(),
            'episodes'  => Episode::count(),
            'users'     => User::where('is_admin', false)->count(),
            'active'    => Subscription::where('status', 'active')->count(),
            'watched'   => WatchHistory::count(),
        ];

        $latestDramas = Drama::query()
            ->select(['id', 'title', 'slug', 'status', 'rating', 'created_at'])
            ->latest()
            ->take(8)
            ->get();

        $latestUsers = User::query()
            ->select(['id', 'name', 'telegram_username', 'created_at'])
            ->where('is_admin', false)
            ->latest()
            ->take(8)
            ->get();

        return view('web.pages.admin.dashboard', compact('stats', 'latestDramas', 'latestUsers'));
    }
}
