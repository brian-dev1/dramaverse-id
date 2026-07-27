<?php

namespace App\Enums;

enum PaymentGateway: string
{
    case MIDTRANS = 'midtrans';

    case XENDIT = 'xendit';

    case TRIPAY = 'tripay';
}