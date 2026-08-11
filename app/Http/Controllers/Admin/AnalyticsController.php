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
        $bolehKeuangan = $request->user()?->can('finance.view') ?? false;

        $section = in_array($request->query('section'), AnalyticsService::SECTIONS, true)
            ? (string) $request->query('section')
            : 'business';

        // Tab Keuangan seluruhnya angka rupiah. Tanpa `finance.view` tab ini
        // tidak muncul — dan URL langsung ?section=financial dilempar balik ke
        // Bisnis, bukan 403: menu Analytics-nya sendiri memang boleh dibuka,
        // yang tidak boleh hanya isinya.
        if ($section === 'financial' && ! $bolehKeuangan) {
            $section = 'business';
        }

        $period = AnalyticsPeriod::fromRequest($request->query('period'));

        $data = $this->analytics->section($section, $period);

        // Tab Bisnis mencampur angka non-uang (pengguna, pendaftaran) dengan
        // pendapatan. Kunci uangnya dibuang di sini, sebelum sampai ke view,
        // supaya tidak ada jalan angka itu terkirim ke peramban — termasuk
        // lewat sumber data grafik.
        //
        // Dibatasi ke seksi Bisnis dengan sengaja. Seksi lain memakai kunci
        // `growth` untuk hal yang sama sekali berbeda (pertumbuhan penyimpanan
        // berbentuk labels/values), dan membuang kunci secara membabi buta di
        // semua seksi adalah cara mudah merusak grafik yang tidak ada
        // hubungannya dengan uang.
        if ($section === 'business' && ! $bolehKeuangan) {
            unset($data['revenue'], $data['growth']['revenue']);
        }

        return view('web.pages.admin.analytics', [
            'section'  => $section,
            'sections' => $this->sectionLabels($bolehKeuangan),
            'period'   => $period,
            'periods'  => AnalyticsPeriod::options(),
            'data'     => $data,
        ]);
    }

    /** @return array<string,string> */
    private function sectionLabels(bool $bolehKeuangan = true): array
    {
        $labels = [
            'business'  => 'Bisnis',
            'content'   => 'Konten',
            'telegram'  => 'Telegram',
            'storage'   => 'Penyimpanan',
            'financial' => 'Keuangan',
        ];

        if (! $bolehKeuangan) {
            unset($labels['financial']);
        }

        return $labels;
    }
}
