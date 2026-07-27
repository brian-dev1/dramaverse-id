<?php

namespace App\Http\Controllers;

use App\Services\HomeService;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function __construct(
        protected HomeService $homeService
    ) {
    }

    public function __invoke()
    {
        return view(
            'home',
            $this->homeService->home(Auth::id())
        );
    }
}