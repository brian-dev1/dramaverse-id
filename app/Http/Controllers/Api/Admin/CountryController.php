<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Country;
use App\Http\Controllers\Controller;
use App\Services\AdminCountryService;
use App\Http\Requests\Admin\StoreCountryRequest;
use App\Http\Requests\Admin\UpdateCountryRequest;

class CountryController extends Controller
{
    public function __construct(
        protected AdminCountryService $service
    ) {
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->paginate()
        ]);
    }

    public function store(StoreCountryRequest $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->store(
                $request->validated()
            )
        ]);
    }

    public function update(
        UpdateCountryRequest $request,
        Country $country
    ) {
        return response()->json([
            'success' => true,
            'data' => $this->service->update(
                $country,
                $request->validated()
            )
        ]);
    }

    public function destroy(
        Country $country
    ) {
        $this->service->delete($country);

        return response()->json([
            'success' => true
        ]);
    }
}