<?php

namespace App\Enums;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;

enum PaymentMethod: string
{
    case CASH = 'cash';
    case BANK = 'bank';
    case BARTER = 'barter';
    case TRANSFER = 'transfer';
    case CARD = 'card';

    /** Pagos nuevos: el enum conserva `card` para histórico, pero no se acepta. */
    public static function ruleForNewPayments(): EnumRule
    {
        return Rule::enum(self::class)->except([self::CARD]);
    }
}