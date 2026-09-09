<?php

namespace App\DTOs;

use App\Http\Requests\UpdateContractRequest;

class UpdateContractDTO
{
    public function __construct(
        public readonly string $contractNumber,
        public readonly ?int $customerId,
        public readonly ?string $sellerName,
        public readonly int $lotId,
        public readonly float $salePrice,
        public readonly ?float $downPaymentPactada,
        public readonly ?int $termMonths,
        public readonly float $interestRate,
        public readonly string $startDate,
        public readonly ?string $initialPaymentDate,
        public readonly ?string $firstInstallmentDate,
        public readonly ?string $regularPaymentStartDate,
        public readonly ?int $preventaInstallmentsCount,
        public readonly bool $isCustomPlan,
        public readonly bool $isSpecialLot,
        public readonly ?array $promises,
        public readonly array $coTitularIds = [],
    ) {}

    public static function fromRequest(UpdateContractRequest $request): self
    {
        return new self(
            contractNumber: $request->validated('contract_number'),
            customerId: $request->validated('customer_id')
                ? (int) $request->validated('customer_id')
                : null,
            sellerName: $request->validated('seller_name'),
            lotId: (int) $request->validated('lot_id'),
            salePrice: (float) $request->validated('sale_price'),
            downPaymentPactada: $request->validated('down_payment_pactada') !== null
                ? (float) $request->validated('down_payment_pactada')
                : null,
            termMonths: $request->validated('term_months') !== null
                ? (int) $request->validated('term_months')
                : null,
            interestRate: $request->validated('interest_rate') !== null
                ? (float) $request->validated('interest_rate')
                : 0.0,
            startDate: $request->validated('start_date'),
            initialPaymentDate: $request->validated('initial_payment_date'),
            firstInstallmentDate: $request->validated('first_installment_date'),
            regularPaymentStartDate: $request->validated('regular_payment_start_date'),
            preventaInstallmentsCount: $request->validated('preventa_installments_count') !== null
                ? (int) $request->validated('preventa_installments_count')
                : null,
            isCustomPlan: (bool) ($request->validated('is_custom_plan') ?? false),
            isSpecialLot: (bool) ($request->validated('is_special_lot') ?? false),
            promises: $request->validated('promises'),
            coTitularIds: self::normalizeCoTitularIds(
                $request->validated('co_titular_ids') ?? []
            ),
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