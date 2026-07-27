<?php

namespace App\Http\Controllers;

use App\Services\DramaService;

class DramaController extends Controller
{
    public function __construct(
        protected DramaService $dramaService
    ) {
    }

    public function __invoke(string $slug)
    {
        $drama = $this->dramaService
            ->detail($slug);

        abort_if(
            ! $drama,
            404
        );

        return view(
            'drama.show',
            compact('drama')
        );
    }
}