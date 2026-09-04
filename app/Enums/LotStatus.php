<?php

namespace App\Enums;

enum LotStatus: string
{
    case DISPONIBLE = 'disponible';
    case PREVENTA = 'preventa';
    case VENDIDO = 'vendido';
    case ABOGADO = 'abogado'; // En cobro jurídico/pleito
    case SEPARADO = 'separado';

    public function label(): string
    {
        return match ($this) {
            self::DISPONIBLE => 'Disponible',
            self::PREVENTA => 'Preventa',
            self::VENDIDO => 'Vendido',
            self::ABOGADO => 'Renegociación',
            self::SEPARADO => 'Separado',
        };
    }
}
