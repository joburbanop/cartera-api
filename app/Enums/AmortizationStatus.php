<?php

namespace App\Enums;

enum AmortizationStatus: string
{
    case UNPAID = 'sin_pagar';     // Sin pagar
    case PAID = 'pagada';         // Pagada totalmente
    case PARTIAL = 'parcial';   // Abono parcial
    case OVERDUE = 'vencida';   // En mora (vencida)
}