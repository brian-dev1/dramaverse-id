<?php

namespace App\Repositories;

use App\Models\Country;
use App\Repositories\Contracts\AdminCountryRepositoryInterface;

class AdminCountryRepository implements AdminCountryRepositoryInterface
{
    public function paginate()
    {
        return Country::query()
            ->latest()
            ->paginate(20);
    }

    public function store(array $data): Country
    {
        return Country::create($data);
    }

    public function update(
        Country $country,
        array $data
    ): Country {

        $country->update($data);

        return $country;
    }

    public function delete(
        Country $country
    ): void {

        $country->delete();

    }
}