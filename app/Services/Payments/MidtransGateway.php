<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentGatewayInterface;

class MidtransGateway implements PaymentGatewayInterface
{
    public function createTransaction(array $payload)
    {
        /**
         * TODO
         * Midtrans Snap/API
         */
    }

    public function checkStatus(string $reference)
    {
        /**
         * TODO
         */
    }

    public function cancel(string $reference)
    {
        /**
         * TODO
         */
    }

    public function refund(string $reference)
    {
        /**
         * TODO
         */
    }
}