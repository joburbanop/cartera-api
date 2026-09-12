<?php

namespace App\Enums;

enum TransactionType: string
{
    case DOWN_PAYMENT = 'down_payment';
    case REGULAR_PAYMENT = 'regular_payment';
    case EXTRAORDINARY_PAYMENT = 'extraordinary_payment';
    /**
     * Un solo movimiento bancario repartido entre la cuota inicial y cuotas
     * regulares. El reparto vive en `transaction_allocations`.
     */
    case SPLIT_PAYMENT = 'pago_mixto';
    /** Pago del saldo diferido de interés (fuera del plan; no toca cuotas). */
    case DEFERRED_INTEREST = 'interes_diferido';
    /** Cobro del acumulado de residuales menores (fuera del plan; no toca cuotas). */
    case RESIDUAL_COLLECTION = 'residual_collection';
    case REFUND = 'refund';
    case PAYMENT_REVERSAL = 'payment_reversal';
}
