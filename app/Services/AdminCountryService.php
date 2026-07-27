<?php

namespace App\Services;

use App\Models\Country;
use App\Repositories\Contracts\AdminCountryRepositoryInterface;

class AdminCountryService
{
    public function __construct(
        protected AdminCountryRepositoryInterface $repository
    ) {
    }

    public function paginate()
    {
        return $this->repository->paginate();
    }

    public function store(array $data)
    {
        return $this->repository->store($data);
    }

    public function update(
        Country $country,
        array $data
    )
    {
        return $this->repository->update(
            $country,
            $data
        );
    }

    public function delete(
        Country $country
    )
    {
        $this->repository->delete($country);
    }
}