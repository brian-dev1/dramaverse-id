<?php

namespace App\Services\Web;

use App\Repositories\Web\WebSearchRepository;
use Illuminate\Http\Request;

class WebSearchService
{
    public function __construct(

        protected WebSearchRepository $repository

    ){}

    public function search(Request $request)
    {
        return $this->repository->search(

            $request

        );
    }
}