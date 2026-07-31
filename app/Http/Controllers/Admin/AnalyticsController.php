<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AnalyticsPeriod;
use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Dashboard Business Intelligence.
 *
 * Lima seksi dalam satu halaman bertab: bisnis, konten, Telegram, storage,
 * dan keuangan. Hanya seksi yang sedang dibuka yang dihitung — memuat
 * kelimanya sekaligus berarti belasan query agregat berjalan untuk empat tab
 * yang mungkin tidak akan dilihat.
 *
 * Controller ini tidak menghitung apa pun sendiri. Seluruh angka datang dari
 * `AnalyticsService`, yang membacanya lewat `AnalyticsRepositoryInterface` —
 * jalur yang sama dipakai perintah pemanas cache, sehingga tidak ada
 * kemungkinan halaman dan cache menghitung dengan cara berbeda.
 */
class AnalyticsController extends Controller
{
    public function __construct(
        protected AnalyticsService $analytics
    ) {
    }

    public function index(Request $request): View
    {
        $section = in_array($request->query('section'), AnalyticsService::SECTIONS, true)
            ? (string) $request->query('section')
            : 'business';

        $period = AnalyticsPeriod::fromRequest($request->query('period'));

        return view('web.pages.admin.analytics', [
            'section'  => $section,
            'sections' => $this->sectionLabels(),
            'period'   => $period,
            'periods'  => AnalyticsPeriod::options(),
            'data'     => $this->analytics->section($section, $period),
        ]);
    }

    /** @return array<string,string> */
    private function sectionLabels(): array
    {
        return [
            'business'  => 'Bisnis',
            'content'   => 'Konten',
            'telegram'  => 'Telegram',
            'storage'   => 'Penyimpanan',
            'financial' => 'Keuangan',
        ];
    }
}
