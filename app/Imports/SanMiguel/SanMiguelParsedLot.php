<?php

namespace App\Imports\SanMiguel;

class SanMiguelParsedLot
{
    public function __construct(
        public readonly string $sheetName,
        public readonly string $lotNumber,
        public readonly string $kind,
        public readonly string $salePrice,
        public readonly string $downPaymentPactada,
        public readonly float $interestRate,
        public readonly int $termMonths,
        public readonly bool $isSpecialLot,
        /** @var list<SanMiguelParsedClient> */
        public readonly array $clients,
        /** @var list<SanMiguelParsedPayment> */
        public readonly array $payments,
        public readonly ?string $lastExcelSaldo,
        public readonly string $sumPayments,
        public readonly string $expectedSaldo,
        public readonly bool $saldoMatches,
        public readonly string $saldoDelta,
        /** @var list<string> */
        public readonly array $issues,
        public readonly ?\Carbon\Carbon $firstNperDate = null,
        /** Hoja de vida que alimentó los pagos, si el lote la tiene. */
        public readonly ?SanMiguelLifeSheet $lifeSheet = null,
    ) {}

    public function paymentsSource(): string
    {
        return $this->lifeSheet !== null ? 'hoja de vida' : 'libro de amortización';
    }
}
