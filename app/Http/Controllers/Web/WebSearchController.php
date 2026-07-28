<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Web\WebSearchService;
use Illuminate\Http\Request;

class WebSearchController extends Controller
{
    public function __construct(

        protected WebSearchService $service

    ){}

    public function index()
    {
        return view(

            'web.pages.web-search'

        );
    }

    public function ajax(Request $request)
    {
        return response()->json(

            $this->service->search(

                $request

            )

        );
    }
}