<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Drama;
use App\Services\Admin\StatsService;
use Illuminate\Contracts\View\View;

class AnalyticsController extends Controller
{
    public function __construct(
        protected StatsService $stats
    ) {
    }

    public function index(): View
    {
        return view('web.pages.admin.analytics', [
            'watchPerDay'   => $this->stats->watchPerDay(30),
            'watchPerMonth' => $this->stats->watchPerMonth(12),
            'userGrowth'    => $this->stats->userGrowth(30),
            'revenue'       => $this->stats->revenuePerMonth(12),
            'topDramas'     => $this->stats->topDramas(10),
            'topGenres'     => $this->stats->topGenres(8),
            'topCountries'  => $this->stats->topCountries(8),
            'activeUsers'   => $this->stats->mostActiveUsers(10),
            'trending'      => Drama::query()
                ->select(['id', 'title', 'trending_score', 'views'])
                ->where('is_trending', true)
                ->orderByDesc('trending_score')
                ->take(10)
                ->get(),
        ]);
    }
}
