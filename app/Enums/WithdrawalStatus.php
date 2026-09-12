<?php

namespace App\Enums;

enum WithdrawalStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::COMPLETED => 'Completado',
        };
    }
}