<?php

namespace App\Imports\SanMiguel;

use App\Enums\PaymentMethod;
use Carbon\Carbon;

class SanMiguelParsedPayment
{
    public function __construct(
        public readonly Carbon $date,
        public readonly string $amount,
        public readonly string $concept,
        public readonly ?string $receiptNumber,
        public readonly PaymentMethod $paymentMethod,
        public readonly ?string $observation,
        public readonly ?string $excelSaldo,
        public readonly bool $isDownPayment,
        public readonly int $excelRow,
        public readonly ?string $collectionOption = null,
    ) {}
}
