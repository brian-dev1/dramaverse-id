<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SearchService;

class SearchController extends Controller
{
    public function __construct(
        protected SearchService $service
    ) {
    }

    public function __invoke(Request $request)
    {
        $keyword = trim($request->get('q', ''));

        $results = $this->service->search($keyword);

        return view('search', [
            'keyword' => $keyword,
            'results' => $results['dramas'],
            'searchResult' => $results,
        ]);
    }
}