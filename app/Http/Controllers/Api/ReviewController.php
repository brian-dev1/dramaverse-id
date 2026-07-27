<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Services\ReviewService;

class ReviewController extends Controller
{
    public function __construct(
        protected ReviewService $service
    ){
    }

    public function index($dramaId)
    {
        return ReviewResource::collection(
            $this->service->byDrama($dramaId)
        );
    }
}