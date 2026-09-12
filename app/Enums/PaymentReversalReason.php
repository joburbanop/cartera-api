<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentReversalReason: string
{
    case ERROR_CAPTURA = 'error_captura';
    case DUPLICADO = 'duplicado';
    case MAL_IMPUTADO = 'mal_imputado';
    case OTRO = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::ERROR_CAPTURA => 'Error de captura',
            self::DUPLICADO => 'Pago duplicado',
            self::MAL_IMPUTADO => 'Mal imputado',
            self::OTRO => 'Otro',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
