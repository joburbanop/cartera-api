<?php

namespace App\Enums;

enum WithdrawalCause: string
{
    case RETRACTO_DE_LEY = 'retracto_de_ley';
    case FUERZA_MAYOR = 'fuerza_mayor';
    case VOLUNTARIO = 'voluntario';

    public function label(): string
    {
        return match ($this) {
            self::RETRACTO_DE_LEY => 'Retracto de ley',
            self::FUERZA_MAYOR => 'Fuerza mayor',
            self::VOLUNTARIO => 'Voluntario',
        };
    }
}