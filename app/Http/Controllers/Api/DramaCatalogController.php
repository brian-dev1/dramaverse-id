<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DramaResource;
use App\Services\DramaCatalogService;

class DramaCatalogController extends Controller
{
    public function __construct(
        protected DramaCatalogService $service
    ) {
    }

    public function __invoke()
    {
        $dramas = $this->service->paginate();

        return DramaResource::collection(
            $dramas
        );
    }
}