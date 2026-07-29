<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Admin\StatsService;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __construct(
        protected StatsService $stats
    ) {
    }

    public function index(): View
    {
        return view('web.pages.admin.dashboard', [
            'summary'      => $this->stats->summary(),
            'watchPerDay'  => $this->stats->watchPerDay(14),
            'userGrowth'   => $this->stats->userGrowth(30),
            'topDramas'    => $this->stats->topDramas(8),
            'topGenres'    => $this->stats->topGenres(8),
            'topCountries' => $this->stats->topCountries(8),
            'recentLogs'   => ActivityLog::with('user:id,name')->latest()->take(10)->get(),
        ]);
    }
}
