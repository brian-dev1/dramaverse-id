<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\SettingResource;
use App\Services\SettingService;

class SettingController extends Controller
{
    public function __construct(
        protected SettingService $service
    ){
    }

    public function index()
    {
        return SettingResource::collection(
            $this->service->all()
        );
    }

    public function update(Request $request)
    {
        foreach($request->all() as $key=>$value){

            $this->service->set(
                $key,
                $value
            );

        }

        return response()->json([

            'success'=>true

        ]);
    }
}