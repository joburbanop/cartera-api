<?php

namespace App\Enums;

enum PaymentPromiseStatusEnum: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
}
