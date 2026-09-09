<?php

namespace App\Imports\SanMiguel;

use App\Enums\PaymentMethod;
use Carbon\Carbon;

/**
 * Una fila de pago de la hoja de vida: lo que el equipo anotó al recibir la plata.
 */
class SanMiguelLifeSheetRow
{
    public function __construct(
        public readonly Carbon $date,
        public readonly string $amount,
        public readonly string $concept,
        public readonly ?string $receiptNumber,
        public readonly PaymentMethod $paymentMethod,
        public readonly int $excelRow,
        /** Saldo acumulado que la propia hoja calcula en esa fila. */
        public readonly ?string $excelSaldo = null,
    ) {}
}
