<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Drama;

class HomeController extends Controller
{
    private const PER_PAGE = 24;

    public function __invoke()
    {
        // Beranda gaya aplikasi HP: satu daftar drama berhalaman,
        // tanpa hero dan tanpa rail geser.
        $dramas = Drama::query()
            ->select([
                'id', 'title', 'slug', 'poster', 'gradient', 'country_id',
                'release_year', 'total_episode', 'status', 'rating', 'views',
                'is_vip', 'published_at',
            ])
            ->with([
                'country:id,name,slug,flag_emoji',
                'genres:id,name,slug',
            ])
            ->published()
            ->latestRelease()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('web.pages.home', compact('dramas'));
    }
}
