<?php

namespace App\Services;

use App\Services\Payments\Contracts\PaymentGatewayInterface;

class PaymentService
{
    public function __construct(
        protected PaymentGatewayInterface $gateway
    ) {
    }

    public function create(array $payload)
    {
        return $this->gateway->createTransaction($payload);
    }

    public function status(string $reference)
    {
        return $this->gateway->checkStatus($reference);
    }

    public function cancel(string $reference)
    {
        return $this->gateway->cancel($reference);
    }

    public function refund(string $reference)
    {
        return $this->gateway->refund($reference);
    }
}