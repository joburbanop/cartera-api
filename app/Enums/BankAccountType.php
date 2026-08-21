<?php

namespace App\Enums;

enum BankAccountType: string
{
    case SAVINGS = 'savings';
    case CHECKING = 'checking';

    // Opcional pero muy útil: Un método para devolver el nombre bonito en español
    public function label(): string
    {
        return match($this) {
            self::SAVINGS => 'Ahorros',
            self::CHECKING => 'Corriente',
        };
    }
}