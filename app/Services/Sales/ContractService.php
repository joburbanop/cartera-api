<?php

namespace App\Services\Sales;

use App\DTOs\ContractPaymentPromiseDTO;
use App\DTOs\CreateContractDTO;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Models\Contract;
use App\Models\Lot;
use App\Services\ContractPaymentPromiseService;
use App\Services\Financial\Amortization\AmortizationService;
use App\Support\FinancialRules;
use Illuminate\Database\Eloquent\Builder;
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
                'interest_rate' => FinancialRules::effectiveInterestRate(
                    $dto->termMonths,
                    $dto->interestRate,
                    $dto->isSpecialLot,
                ),
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

    /**
     * @param array{
     *     contract_number?: string|null,
     *     customer?: string|null,
     *     project_id?: int|string|null,
     *     lot_number?: string|null,
     *     status?: string|null,
     *     cartera?: string|null,
     *     start_date_from?: string|null,
     *     start_date_to?: string|null
     * } $filters
     */
    public function getAllContracts(int $perPage = 15, ?int $lotId = null, array $filters = [])
    {
        $relations = ['customer', 'customers', 'lot'];

        if ($lotId !== null) {
            $relations[] = 'transactions';
        }

        $query = Contract::with($relations)->latest();

        if ($lotId !== null) {
            $query->where('lot_id', $lotId);
        }

        $contractNumber = trim((string) ($filters['contract_number'] ?? ''));

        if ($contractNumber !== '') {
            $query->where(function (Builder $builder) use ($contractNumber) {
                $builder->where('contract_number', $contractNumber)
                    ->orWhere('contract_number', 'like', '%'.$contractNumber.'%');
            });
        }

        $customer = trim((string) ($filters['customer'] ?? ''));

        if ($customer !== '') {
            $like = '%'.$customer.'%';

            $query->where(function (Builder $builder) use ($like) {
                $builder
                    ->whereHas('customer', function (Builder $holder) use ($like) {
                        $holder
                            ->where('customers.name', 'like', $like)
                            ->orWhere('customers.document_number', 'like', $like);
                    })
                    ->orWhereHas('customers', function (Builder $holders) use ($like) {
                        $holders
                            ->where('customers.name', 'like', $like)
                            ->orWhere('customers.document_number', 'like', $like);
                    });
            });
        }

        $projectId = isset($filters['project_id']) ? (int) $filters['project_id'] : 0;

        if ($projectId > 0) {
            $query->whereHas('lot', fn (Builder $lot) => $lot->where('lots.project_id', $projectId));
        }

        $lotNumber = trim((string) ($filters['lot_number'] ?? ''));

        if ($lotNumber !== '') {
            $query->whereHas('lot', function (Builder $lot) use ($lotNumber) {
                $lot->where(function (Builder $builder) use ($lotNumber) {
                    $builder->where('lots.number', $lotNumber)
                        ->orWhere('lots.number', 'like', '%'.$lotNumber.'%');
                });
            });
        }

        $status = trim((string) ($filters['status'] ?? ''));

        if ($status !== '' && ContractStatus::tryFrom($status)) {
            $query->where('status', $status);
        }

        $cartera = trim((string) ($filters['cartera'] ?? ''));

        if ($cartera === 'mora') {
            $query->whereHas(
                'installments',
                fn (Builder $installments) => $this->overdueInstallments($installments)
            );
        } elseif ($cartera === 'al_dia') {
            $query->whereDoesntHave(
                'installments',
                fn (Builder $installments) => $this->overdueInstallments($installments)
            );
        }

        $from = $this->validDate($filters['start_date_from'] ?? null);

        if ($from !== null) {
            $query->whereDate('start_date', '>=', $from);
        }

        $to = $this->validDate($filters['start_date_to'] ?? null);

        if ($to !== null) {
            $query->whereDate('start_date', '<=', $to);
        }

        return $query->paginate($perPage);
    }

    private function overdueInstallments(Builder $query): Builder
    {
        return $query
            ->where('installment_number', '>', 0)
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->calendarOverdue();
    }

    private function validDate(mixed $value): ?string
    {
        $date = trim((string) $value);

        if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return $date;
    }
}
