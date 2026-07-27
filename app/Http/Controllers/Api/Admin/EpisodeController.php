<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Episode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEpisodeRequest;
use App\Http\Requests\Admin\UpdateEpisodeRequest;
use App\Http\Resources\EpisodeResource;
use App\Services\AdminEpisodeService;

class EpisodeController extends Controller
{
    public function __construct(
        protected AdminEpisodeService $service
    ) {
    }

    public function index()
    {
        return EpisodeResource::collection(
            $this->service->paginate()
        );
    }

    public function store(
        StoreEpisodeRequest $request
    ) {
        return new EpisodeResource(
            $this->service->store(
                $request->validated()
            )
        );
    }

    public function update(
        UpdateEpisodeRequest $request,
        Episode $episode
    ) {
        return new EpisodeResource(
            $this->service->update(
                $episode,
                $request->validated()
            )
        );
    }

    public function destroy(
        Episode $episode
    ) {
        $this->service->delete($episode);

        return response()->json([
            'success' => true
        ]);
    }
}