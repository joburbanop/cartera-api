<?php

namespace App\Enums;

/** A dónde se imputó una porción de un pago repartido. */
enum AllocationTarget: string
{
    case DOWN_PAYMENT = 'down_payment';
    case INSTALLMENT = 'installment';
    case CAPITAL = 'capital';

    public function label(): string
    {
        return match ($this) {
            self::DOWN_PAYMENT => 'Cuota inicial',
            self::INSTALLMENT => 'Cuota regular',
            self::CAPITAL => 'Abono a capital',
        };
    }
}
