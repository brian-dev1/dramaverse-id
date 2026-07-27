<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MediaUploadRequest;
use App\Services\MediaService;

class MediaController extends Controller
{
    public function __construct(
        protected MediaService $service
    ){
    }

    public function upload(
        MediaUploadRequest $request
    ){

        return response()->json([

            'success'=>true,

            'data'=>$this->service->upload(

                $request->file('file'),

                $request->directory,

                auth()->id()

            )

        ]);

    }

    public function destroy(int $id)
    {

        $this->service->delete($id);

        return response()->json([

            'success'=>true

        ]);

    }
}