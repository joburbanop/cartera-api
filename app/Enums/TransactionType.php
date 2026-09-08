<?php

namespace App\Enums;

enum TransactionType: string
{
    case DOWN_PAYMENT = 'down_payment';
    case REGULAR_PAYMENT = 'regular_payment';
    case EXTRAORDINARY_PAYMENT = 'extraordinary_payment';
    /** Pago del saldo diferido de interés (fuera del plan; no toca cuotas). */
    case DEFERRED_INTEREST = 'interes_diferido';
    case REFUND = 'refund';
}