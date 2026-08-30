<?php

declare(strict_types=1);

namespace App\Enums;

enum RoleName: string
{
    case SOCIO_GERENCIA = 'socio_gerencia';
    case ADMIN_SISTEMA = 'admin_sistema';
    case ADMINISTRADOR = 'administrador';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
