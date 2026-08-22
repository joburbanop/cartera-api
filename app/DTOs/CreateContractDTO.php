<?php

namespace App\DTOs;

use App\Http\Requests\StoreContractRequest;

class CreateContractDTO
{
    public function __construct(
        public readonly string $contractNumber,
        public readonly int $customerId,
        public readonly int $lotId,
        public readonly ?string $sellerName,
        public readonly float $salePrice,
        public readonly float $downPaymentPactada,
        public readonly int $termMonths,
        public readonly float $interestRate,
        public readonly string $startDate,
        public readonly string $initialPaymentDate,
        public readonly string $regularPaymentStartDate,
        public readonly int $preventaInstallmentsCount,
        public readonly ?int $createdBy
    ) {}

    public static function fromRequest(StoreContractRequest $request): self
    {
        return new self(
            contractNumber: $request->validated('contract_number'),
            customerId: $request->validated('customer_id'),
            lotId: $request->validated('lot_id'),
            sellerName: $request->validated('seller_name'),
            salePrice: $request->validated('sale_price'),
            downPaymentPactada: $request->validated('down_payment_pactada'),
            termMonths: $request->validated('term_months'),
            interestRate: $request->validated('interest_rate') ?? 1.00,
            startDate: $request->validated('start_date'),
            initialPaymentDate: $request->validated('initial_payment_date'),
            regularPaymentStartDate: $request->validated('regular_payment_start_date'),
            preventaInstallmentsCount: $request->validated('preventa_installments_count') ?? 0,
            createdBy: auth()->id()
        );
    }
}