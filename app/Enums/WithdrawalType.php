<?php

namespace App\Enums;

enum WithdrawalType: string
{
    case PREVENTA = 'preventa';
    case VENTA = 'venta';

    public function label(): string
    {
        return match ($this) {
            self::PREVENTA => 'Preventa',
            self::VENTA => 'Venta',
        };
    }
}