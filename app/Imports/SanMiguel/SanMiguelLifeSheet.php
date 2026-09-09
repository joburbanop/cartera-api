<?php

namespace App\Imports\SanMiguel;

/**
 * Hoja de vida de un lote: encabezado con los datos del titular y el historial
 * de pagos tal como lo lleva el equipo comercial.
 */
class SanMiguelLifeSheet
{
    public function __construct(
        public readonly string $lotNumber,
        public readonly string $sheetName,
        public readonly string $fileName,
        public readonly string $financedValue,
        public readonly string $downPaymentPactada,
        public readonly string $installmentValue,
        public readonly ?int $termMonths,
        public readonly string $clientName,
        public readonly string $clientDocument,
        public readonly ?string $address,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $advisor,
        /** @var list<SanMiguelLifeSheetRow> */
        public readonly array $rows,
        /** @var list<string> */
        public readonly array $issues,
    ) {}

    public function sumPayments(): string
    {
        $sum = '0.00';
        foreach ($this->rows as $row) {
            $sum = bcadd($sum, $row->amount, 2);
        }

        return $sum;
    }
}
