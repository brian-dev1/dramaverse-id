<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentGatewayInterface;

class TripayGateway implements PaymentGatewayInterface
{
    public function createTransaction(array $payload)
    {
    }

    public function checkStatus(string $reference)
    {
    }

    public function cancel(string $reference)
    {
    }

    public function refund(string $reference)
    {
    }
}