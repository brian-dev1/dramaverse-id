<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Genre;
use App\Services\Web\WebSearchService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebSearchController extends Controller
{
    public function __construct(
        protected WebSearchService $service
    ) {
    }

    /** Halaman pencarian dengan filter. */
    public function index(): View
    {
        return view('web.pages.search', [
            'genres'    => Genre::active()->get(),
            'countries' => Country::active()->get(),
            'dramas'    => null,
            'keyword'   => '',
        ]);
    }

    /** Hasil pencarian (server-rendered, mendukung pagination). */
    public function result(Request $request): View
    {
        return view('web.pages.search', [
            'genres'    => Genre::active()->get(),
            'countries' => Country::active()->get(),
            'dramas'    => $this->service->search($request),
            'keyword'   => trim((string) $request->get('q', '')),
        ]);
    }

    /**
     * Endpoint realtime untuk pencarian saat mengetik.
     *
     * ## Kenapa bentuknya disusun sendiri, bukan paginator mentah
     *
     * Mengembalikan paginator apa adanya mengirim seluruh kolom model ke
     * peramban — termasuk yang tidak dipakai tampilan — dan `poster` di sana
     * masih berupa jalur penyimpanan, bukan URL yang bisa dipasang di `src`.
     * Halaman jadi harus menyusun URL-nya sendiri, dan aturan penyusunan itu
     * lalu ada di dua tempat.
     *
     * Bentuk ringkas ini juga membuat jawabannya kecil. Endpoint ini dipanggil
     * setiap kali orang berhenti mengetik sesaat, jadi selisih beberapa
     * kilobyte per panggilan terasa di kuota pengguna ponsel.
     */
    public function ajax(Request $request): JsonResponse
    {
        $hasil = $this->service->search($request);

        return response()->json([
            'query' => trim((string) $request->get('q', '')),
            'total' => $hasil->total(),
            'items' => collect($hasil->items())->map(fn ($d) => [
                'title'    => $d->title,
                'url'      => route('web.drama.show', $d->slug),
                'poster'   => $d->poster_url,
                'gradient' => $d->gradient,
                'country'  => $d->country?->name,
                'episodes' => $d->total_episode,
                'vip'      => (bool) $d->is_vip,
            ])->all(),
        ]);
    }
}
