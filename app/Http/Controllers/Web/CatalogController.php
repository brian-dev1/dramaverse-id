<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Drama;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * Halaman daftar katalog: trending, terbaru, rating tertinggi,
 * rilis baru, populer, dan VIP.
 *
 * Semua memakai satu view + satu komponen grid agar tidak ada duplikasi.
 */
class CatalogController extends Controller
{
    private const PER_PAGE = 24;

    public function trending(): View
    {
        return $this->render(
            fn (Builder $q) => $q->trending(),
            'Trending Minggu Ini',
            'Drama yang paling banyak ditonton belakangan ini.',
        );
    }

    public function latest(): View
    {
        return $this->render(
            fn (Builder $q) => $q->latestRelease(),
            'Rilis Terbaru',
            'Episode dan judul yang baru saja tayang.',
        );
    }

    public function topRated(): View
    {
        return $this->render(
            fn (Builder $q) => $q->topRated(),
            'Rating Tertinggi',
            'Judul dengan penilaian terbaik dari penonton.',
        );
    }

    public function newRelease(): View
    {
        return $this->render(
            fn (Builder $q) => $q->where('published_at', '>=', now()->subDays(30))
                ->orderByDesc('published_at'),
            'Baru Rilis',
            'Judul yang tayang dalam 30 hari terakhir.',
        );
    }

    public function popular(): View
    {
        return $this->render(
            fn (Builder $q) => $q->popular(),
            'Populer Minggu Ini',
            'Paling banyak ditonton oleh pengguna DramaVerse.',
        );
    }

    public function vip(): View
    {
        return $this->render(
            fn (Builder $q) => $q->vip()->latestRelease(),
            'Koleksi VIP',
            'Judul eksklusif untuk anggota VIP dan Premium.',
        );
    }

    /**
     * Merender halaman katalog dengan query yang diberikan.
     *
     * @param  callable(Builder): Builder  $scope
     */
    private function render(callable $scope, string $title, string $subtitle): View
    {
        $query = Drama::query()
            ->select([
                'id', 'title', 'slug', 'poster', 'gradient', 'country_id',
                'release_year', 'total_episode', 'status', 'rating', 'views',
                'is_vip', 'published_at',
            ])
            ->with([
                'country:id,name,slug,flag_emoji',
                'genres:id,name,slug',
            ])
            ->published();

        $dramas = $scope($query)->paginate(self::PER_PAGE)->withQueryString();

        return view('web.pages.catalog', compact('dramas', 'title', 'subtitle'));
    }
}
