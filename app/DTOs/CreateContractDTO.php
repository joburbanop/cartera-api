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
        public readonly string $firstInstallmentDate,
        public readonly string $regularPaymentStartDate,
        public readonly int $preventaInstallmentsCount,
        public readonly bool $isCustomPlan,
        public readonly bool $isSpecialLot,
        public readonly ?array $promises,
        public readonly ?int $createdBy,
        public readonly array $coTitularIds = [],
    ) {}

    public static function fromRequest(StoreContractRequest $request): self
    {
        $isSpecialLot = (bool) ($request->validated('is_special_lot') ?? false);
        $salePrice = $request->validated('sale_price');
        $startDate = $request->validated('start_date');
        $downPayment = $isSpecialLot
            ? $salePrice
            : $request->validated('down_payment_pactada');
        $termMonths = $isSpecialLot ? 0 : (int) $request->validated('term_months');
        $interestRate = $isSpecialLot ? 0.0 : ($request->validated('interest_rate') ?? 1.00);
        $firstInstallmentDate = $request->validated('first_installment_date')
            ?? $request->validated('regular_payment_start_date')
            ?? $startDate;
        $regularPaymentStartDate = $request->validated('regular_payment_start_date')
            ?? $firstInstallmentDate;
        $initialPaymentDate = $request->validated('initial_payment_date') ?? $startDate;

        return new self(
            contractNumber: $request->validated('contract_number'),
            customerId: (int) $request->validated('customer_id'),
            lotId: $request->validated('lot_id'),
            sellerName: $request->validated('seller_name'),
            salePrice: $salePrice,
            downPaymentPactada: $downPayment,
            termMonths: $termMonths,
            interestRate: $interestRate,
            startDate: $startDate,
            initialPaymentDate: $initialPaymentDate,
            firstInstallmentDate: $firstInstallmentDate,
            regularPaymentStartDate: $regularPaymentStartDate,
            preventaInstallmentsCount: $isSpecialLot ? 0 : ($request->validated('preventa_installments_count') ?? 0),
            isCustomPlan: $isSpecialLot ? false : (bool) ($request->validated('is_custom_plan') ?? false),
            isSpecialLot: $isSpecialLot,
            promises: $request->validated('promises'),
            createdBy: auth()->id(),
            coTitularIds: self::normalizeCoTitularIds($request->validated('co_titular_ids') ?? []),
        );
    }

    /**
     * @return list<int>
     */
    private static function normalizeCoTitularIds(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
