<?php

namespace App\Enums;

enum ContractStatus: string
{
    case PREVENTA_INACTIVA = 'preventa_inactiva';
    case ACTIVO = 'activo';
    case VENCIDO = 'vencido';
    case TERMINADO = 'terminado';
    case RESCINDIDO = 'rescindido';

    public function label(): string
    {
        return match ($this) {
            self::PREVENTA_INACTIVA => 'Preventa',
            self::ACTIVO => 'Activo',
            self::VENCIDO => 'Vencido',
            self::TERMINADO => 'Terminado',
            self::RESCINDIDO => 'Rescindido',
        };
    }
}