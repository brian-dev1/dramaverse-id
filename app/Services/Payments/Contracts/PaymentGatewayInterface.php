<?php

namespace App\Services\Payments\Contracts;

interface PaymentGatewayInterface
{
    public function createTransaction(array $payload);

    public function checkStatus(string $reference);

    public function cancel(string $reference);

    public function refund(string $reference);
}