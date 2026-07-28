<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Contracts\View\View;

class CountryController extends Controller
{
    private const PER_PAGE = 24;

    public function index(): View
    {
        $countries = Country::active()
            ->withCount(['dramas' => fn ($q) => $q->published()])
            ->get();

        return view('web.pages.country.index', compact('countries'));
    }

    public function show(Country $country): View
    {
        abort_unless($country->is_active, 404);

        $dramas = $country->dramas()
            ->select([
                'id', 'title', 'slug', 'poster', 'gradient', 'country_id',
                'release_year', 'total_episode', 'status', 'rating', 'views',
                'is_vip', 'published_at',
            ])
            ->with(['genres:id,name,slug'])
            ->published()
            ->latestRelease()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('web.pages.country.show', compact('country', 'dramas'));
    }
}
