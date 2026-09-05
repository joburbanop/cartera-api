<?php

namespace App\Services\Sales;

use App\DTOs\ContractPaymentPromiseDTO;
use App\DTOs\CreateContractDTO;
use App\Enums\LotStatus;
use App\Models\Contract;
use App\Models\Lot;
use App\Services\ContractPaymentPromiseService;
use App\Services\Financial\Amortization\AmortizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractService
{
    public function __construct(
        private readonly AmortizationService $amortizationService,
        private readonly ContractPaymentPromiseService $contractPaymentPromiseService,
    ) {}

    public function createContract(CreateContractDTO $dto): Contract
    {
        return DB::transaction(function () use ($dto) {
            $lot = Lot::findOrFail($dto->lotId);

            if (Contract::where('lot_id', $dto->lotId)->exists()) {
                throw ValidationException::withMessages([
                    'lot_id' => 'Este lote ya tiene un contrato activo o registrado y no puede volver a asignarse.',
                ]);
            }

            if ($lot->status !== LotStatus::DISPONIBLE) {
                throw ValidationException::withMessages([
                    'lot_id' => 'Solo se pueden crear contratos sobre lotes disponibles.',
                ]);
            }

            $contract = Contract::create([
                'contract_number' => $dto->contractNumber,
                'customer_id' => $dto->customerId,
                'lot_id' => $dto->lotId,
                'seller_name' => $dto->sellerName,
                'sale_price' => $dto->salePrice,
                'down_payment_pactada' => $dto->downPaymentPactada,
                'term_months' => $dto->termMonths,
                'interest_rate' => $dto->interestRate,
                'start_date' => $dto->startDate,
                'initial_payment_date' => $dto->initialPaymentDate,
                'first_installment_date' => $dto->firstInstallmentDate,
                'regular_payment_start_date' => $dto->regularPaymentStartDate,
                'preventa_installments_count' => $dto->preventaInstallmentsCount,
                'is_custom_plan' => $dto->isCustomPlan,
                'is_special_lot' => $dto->isSpecialLot,
                'created_by' => $dto->createdBy,
            ]);

            $lot->update([
                'status' => LotStatus::PREVENTA,
            ]);

            $this->amortizationService->generateInitialProjection($contract);

            $contract->syncHolders((int) $dto->customerId, $dto->coTitularIds);

            if ($dto->isCustomPlan && ! empty($dto->promises)) {
                $promiseDTOs = array_map(function ($promise, int $index) {
                    return new ContractPaymentPromiseDTO(
                        payment_number: (int) ($promise['payment_number'] ?? ($index + 1)),
                        expected_date: (string) $promise['expected_date'],
                        expected_amount: (float) $promise['expected_amount'],
                        description: $promise['description'] ?? null,
                    );
                }, $dto->promises, array_keys($dto->promises));

                $this->contractPaymentPromiseService->storeCommercialPlan($contract->id, $promiseDTOs);
            }

            return $contract->load(['customer', 'customers', 'lot']);
        });
    }

    public function getAllContracts(int $perPage = 15, ?int $lotId = null)
    {
        $relations = ['customer', 'customers', 'lot'];

        if ($lotId !== null) {
            $relations[] = 'transactions';
        }

        $query = Contract::with($relations)->latest();

        if ($lotId !== null) {
            $query->where('lot_id', $lotId);
        }

        return $query->paginate($perPage);
    }
}
