<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Genre;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreGenreRequest;
use App\Http\Requests\Admin\UpdateGenreRequest;
use App\Services\AdminGenreService;

class GenreController extends Controller
{
    public function __construct(
        protected AdminGenreService $service
    ) {
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->paginate()
        ]);
    }

    public function store(StoreGenreRequest $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->store(
                $request->validated()
            )
        ]);
    }

    public function update(
        UpdateGenreRequest $request,
        Genre $genre
    ) {
        return response()->json([
            'success' => true,
            'data' => $this->service->update(
                $genre,
                $request->validated()
            )
        ]);
    }

    public function destroy(
        Genre $genre
    ) {
        $this->service->delete($genre);

        return response()->json([
            'success' => true
        ]);
    }
}