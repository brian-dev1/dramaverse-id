<?php

namespace App\Repositories\Contracts;

use App\Models\Country;

interface AdminCountryRepositoryInterface
{
    public function paginate();

    public function store(array $data): Country;

    public function update(
        Country $country,
        array $data
    ): Country;

    public function delete(
        Country $country
    ): void;
}