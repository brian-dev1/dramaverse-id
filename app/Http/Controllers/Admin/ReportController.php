<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Drama;
use App\Models\Subscription;
use App\Models\WatchHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(): View
    {
        $topDramas = Drama::query()
            ->select(['id', 'title', 'slug', 'views', 'rating'])
            ->orderByDesc('views')
            ->take(10)
            ->get();

        $watchPerDay = WatchHistory::query()
            ->selectRaw('DATE(last_watched_at) as day, COUNT(*) as total')
            ->whereNotNull('last_watched_at')
            ->where('last_watched_at', '>=', now()->subDays(14))
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $revenue = Subscription::query()
            ->where('status', 'active')
            ->select(DB::raw('COALESCE(SUM(price), 0) as total'))
            ->value('total');

        return view('web.pages.admin.report', compact('topDramas', 'watchPerDay', 'revenue'));
    }
}
