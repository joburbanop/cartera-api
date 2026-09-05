<?php

namespace App\Enums;

enum PaymentPromiseStatusEnum: string
{
    case PAGADA = 'pagada';
    case PARCIAL = 'parcial';
    case VENCIDA = 'vencida';
    case PENDIENTE = 'pendiente';
}
