<?php

namespace App\Enums;

enum AmortizationStatusEnum: string
{
    case PENDING = 'sin_pagar';
    case PARTIAL = 'parcial';
    case PAID = 'pagada';
}
