<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';

    case PAID = 'paid';

    case FAILED = 'failed';

    case EXPIRED = 'expired';

    case CANCELLED = 'cancelled';

    case REFUNDED = 'refunded';
}