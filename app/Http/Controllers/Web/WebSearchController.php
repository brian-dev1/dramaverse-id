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

    /** Endpoint realtime untuk pencarian saat mengetik. */
    public function ajax(Request $request): JsonResponse
    {
        return response()->json($this->service->search($request));
    }
}
