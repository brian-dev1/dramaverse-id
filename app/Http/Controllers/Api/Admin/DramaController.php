<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Drama;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDramaRequest;
use App\Http\Requests\Admin\UpdateDramaRequest;
use App\Http\Resources\DramaResource;
use App\Services\AdminDramaService;

class DramaController extends Controller
{
    public function __construct(
        protected AdminDramaService $service
    ) {
    }

    public function index()
    {
        return DramaResource::collection(
            $this->service->paginate()
        );
    }

    public function store(
        StoreDramaRequest $request
    ) {

        return new DramaResource(

            $this->service->store(

                $request->validated()

            )

        );

    }

    public function update(
        UpdateDramaRequest $request,
        Drama $drama
    ) {

        return new DramaResource(

            $this->service->update(

                $drama,

                $request->validated()

            )

        );

    }

    public function destroy(
        Drama $drama
    ) {

        $this->service->delete(
            $drama
        );

        return response()->json([
            'success'=>true
        ]);

    }
}