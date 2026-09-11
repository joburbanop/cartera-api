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
use App\DTOs\UpdateContractDTO;

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

    public function updateContract(Contract $contract, UpdateContractDTO $dto): Contract
    {
        return DB::transaction(function () use ($contract, $dto) {
            $contract = Contract::query()
                ->lockForUpdate()
                ->findOrFail($contract->id);

            $wasCustomPlan = $contract->is_custom_plan;

            $hasFinancialActivity = $this->hasFinancialActivity($contract);

            $financialFieldsChanged = $this->financialFieldsChanged(
                $contract,
                $dto
            );

            $lotChanged = (int) $contract->lot_id !== $dto->lotId;

            if ($financialFieldsChanged || $lotChanged) {
                if ($hasFinancialActivity) {
                    throw ValidationException::withMessages([
                        'contract' => 'No se pueden modificar las condiciones financieras ni el lote porque el contrato ya tiene actividad financiera.',
                    ]);
                }

                if ($contract->status !== ContractStatus::PREVENTA_INACTIVA) {
                    throw ValidationException::withMessages([
                        'contract' => 'Las condiciones financieras y el lote solo pueden modificarse mientras el contrato esté en preventa inactiva.',
                    ]);
                }
            }

            if ($lotChanged) {
                $this->changeContractLot($contract, $dto->lotId);
            }

            $contract->contract_number = $dto->contractNumber;
            $contract->customer_id = $dto->customerId;
            $contract->seller_name = $dto->sellerName;

            if ($financialFieldsChanged) {
                $contract->sale_price = $dto->salePrice;
                $contract->down_payment_pactada = $dto->downPaymentPactada;
                $contract->term_months = $dto->termMonths;
                $contract->interest_rate = $dto->interestRate;
                $contract->start_date = $dto->startDate;
                $contract->initial_payment_date = $dto->initialPaymentDate;
                $contract->first_installment_date = $dto->firstInstallmentDate;
                $contract->regular_payment_start_date = $dto->regularPaymentStartDate;
                $contract->preventa_installments_count = $dto->preventaInstallmentsCount;
                $contract->is_custom_plan = $dto->isCustomPlan;
                $contract->is_special_lot = $dto->isSpecialLot;
            }

            if ($dto->customerId !== null) {
                $contract->syncHolders(
                    $dto->customerId,
                    $dto->coTitularIds
                );
            }

            $contract->updated_by = auth()->id();
            $contract->save();

            if ($financialFieldsChanged) {
                $this->amortizationService->regenerateInitialProjection($contract);
            }

            if (! $dto->isCustomPlan && $wasCustomPlan) {
                    $this->contractPaymentPromiseService->clearCommercialPlan(
                        $contract->id
                    );
                } elseif ($dto->isCustomPlan && $dto->promises !== null) {
                    if ($hasFinancialActivity) {
                        throw ValidationException::withMessages([
                            'promises' => 'No se pueden modificar las promesas comerciales porque el contrato ya tiene actividad financiera.',
                        ]);
                    }

                    $promiseDTOs = array_map(
                        function (array $promise, int $index) {
                            return new ContractPaymentPromiseDTO(
                                payment_number: (int) ($promise['payment_number'] ?? ($index + 1)),
                                expected_date: (string) $promise['expected_date'],
                                expected_amount: (float) $promise['expected_amount'],
                                description: $promise['description'] ?? null,
                            );
                        },
                        $dto->promises,
                        array_keys($dto->promises)
                    );

                    $this->contractPaymentPromiseService->storeCommercialPlan(
                        $contract->id,
                        $promiseDTOs
                    );
                }

            return $contract->load([
                'customer',
                'customers',
                'lot',
                'lot.project',
            ]);
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
public function getAllContracts(
    int $perPage = 15,
    ?int $lotId = null,
    array $filters = []
) {
    $relations = ['customer', 'customers', 'lot'];

    if ($lotId !== null) {
        $relations[] = 'transactions';
    }

    $query = Contract::with($relations)
        ->withExists([
            'transactions as has_transactions',
            'installments as has_paid_installments' => function (Builder $query) {
                $query->where(function (Builder $query) {
                    $query
                        ->whereNotNull('payment_date')
                        ->orWhereNotNull('receipt_number')
                        ->orWhere('interest_paid', '>', 0)
                        ->orWhere('principal_paid', '>', 0)
                        ->orWhere('extra_payment', '>', 0);
                });
            },
        ])
        ->latest();

    if ($lotId !== null) {
        $query->where('lot_id', $lotId);
    }

    $contractNumber = trim((string) ($filters['contract_number'] ?? ''));

    if ($contractNumber !== '') {
        $query->where(function (Builder $builder) use ($contractNumber) {
            $builder
                ->where('contract_number', $contractNumber)
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

    $projectId = isset($filters['project_id'])
        ? (int) $filters['project_id']
        : 0;

    if ($projectId > 0) {
        $query->whereHas(
            'lot',
            fn (Builder $lot) =>
                $lot->where('lots.project_id', $projectId)
        );
    }

    $lotNumber = trim((string) ($filters['lot_number'] ?? ''));

    if ($lotNumber !== '') {
        $query->whereHas('lot', function (Builder $lot) use ($lotNumber) {
            $lot->where(function (Builder $builder) use ($lotNumber) {
                $builder
                    ->where('lots.number', $lotNumber)
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
            fn (Builder $installments) =>
                $this->overdueInstallments($installments)
        );
    } elseif ($cartera === 'al_dia') {
        $query->whereDoesntHave(
            'installments',
            fn (Builder $installments) =>
                $this->overdueInstallments($installments)
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

    $paginator = $query->paginate($perPage);

    $paginator->getCollection()->transform(function (Contract $contract) {
        $contract->has_financial_activity =
            (bool) $contract->has_transactions ||
            (bool) $contract->has_paid_installments;

        unset(
            $contract->has_transactions,
            $contract->has_paid_installments
        );

        return $contract;
    });

    return $paginator;
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

    public function hasFinancialActivity(Contract $contract): bool
    {
        if ($contract->transactions()->exists()) {
            return true;
        }

        return $contract->installments()
            ->where(function (Builder $query) {
                $query
                    ->whereNotNull('payment_date')
                    ->orWhereNotNull('receipt_number')
                    ->orWhere('interest_paid', '>', 0)
                    ->orWhere('principal_paid', '>', 0)
                    ->orWhere('extra_payment', '>', 0);
            })
            ->exists();
    }

    private function financialFieldsChanged(
    Contract $contract,
    UpdateContractDTO $dto
    ): bool {
        return
            (float) $contract->sale_price !== (float) $dto->salePrice
            || (float) ($contract->down_payment_pactada ?? 0)
                !== (float) ($dto->downPaymentPactada ?? 0)
            || (int) ($contract->term_months ?? 0)
                !== (int) ($dto->termMonths ?? 0)
            || (float) ($contract->interest_rate ?? 0)
                !== (float) $dto->interestRate
            || $this->dateValue($contract->start_date)
                !== $dto->startDate
            || $this->dateValue($contract->initial_payment_date)
                !== $dto->initialPaymentDate
            || $this->dateValue($contract->first_installment_date)
                !== $dto->firstInstallmentDate
            || $this->dateValue($contract->regular_payment_start_date)
                !== $dto->regularPaymentStartDate
            || (int) ($contract->preventa_installments_count ?? 0)
                !== (int) ($dto->preventaInstallmentsCount ?? 0)
            || (bool) $contract->is_custom_plan !== $dto->isCustomPlan
            || (bool) $contract->is_special_lot !== $dto->isSpecialLot;
    }
    private function dateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \Carbon\CarbonInterface
            ? $value->toDateString()
            : (string) $value;
    }

   private function changeContractLot(
    Contract $contract,
    int $newLotId
    ): void {
        if ((int) $contract->lot_id === $newLotId) {
            return;
        }

        $oldLotId = (int) $contract->lot_id;

        $lotIds = collect([$oldLotId, $newLotId])
            ->unique()
            ->sort()
            ->values();

        $lots = Lot::query()
            ->whereIn('id', $lotIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $oldLot = $lots->get($oldLotId);
        $newLot = $lots->get($newLotId);

        if (! $oldLot) {
            throw ValidationException::withMessages([
                'lot_id' => 'El lote actual del contrato no existe.',
            ]);
        }

        if (! $newLot) {
            throw ValidationException::withMessages([
                'lot_id' => 'El nuevo lote no existe.',
            ]);
        }

        if ((int) $newLot->project_id !== (int) $oldLot->project_id) {
            throw ValidationException::withMessages([
                'lot_id' => 'El nuevo lote debe pertenecer al mismo proyecto del contrato.',
            ]);
        }

        if ($newLot->status !== LotStatus::DISPONIBLE) {
            throw ValidationException::withMessages([
                'lot_id' => 'Solo se puede cambiar el contrato a un lote disponible.',
            ]);
        }

        $newLotHasContract = Contract::query()
            ->where('lot_id', $newLot->id)
            ->whereNull('deleted_at')
            ->where('id', '!=', $contract->id)
            ->where('status', '!=', ContractStatus::RESCINDIDO->value)
            ->exists();

        if ($newLotHasContract) {
            throw ValidationException::withMessages([
                'lot_id' => 'El nuevo lote ya está asignado a otro contrato.',
            ]);
        }

        $oldLot->update([
            'status' => LotStatus::DISPONIBLE,
            'updated_by' => auth()->id(),
        ]);

        $newLot->update([
            'status' => LotStatus::PREVENTA,
            'updated_by' => auth()->id(),
        ]);

        $contract->lot_id = $newLot->id;
    } 
    
   public function archiveContract(Contract $contract): Contract
    {
        return DB::transaction(function () use ($contract) {
            $contract = Contract::query()
                ->lockForUpdate()
                ->findOrFail($contract->id);

            if (! in_array($contract->status, [
                ContractStatus::PREVENTA_INACTIVA,
                ContractStatus::TERMINADO,
                ContractStatus::RESCINDIDO,
            ], true)) {
                throw ValidationException::withMessages([
                    'contract' => 'El contrato no puede archivarse en su estado actual.',
                ]);
            }

            if (
                $contract->status === ContractStatus::PREVENTA_INACTIVA
                && $this->hasFinancialActivity($contract)
            ) {
                throw ValidationException::withMessages([
                    'contract' => 'No se puede archivar un contrato que ya tiene actividad financiera.',
                ]);
            }

            $lot = Lot::query()
                ->lockForUpdate()
                ->findOrFail($contract->lot_id);

            if ($contract->status !== ContractStatus::TERMINADO) {
                $lot->update([
                    'status' => LotStatus::DISPONIBLE,
                    'updated_by' => auth()->id(),
                ]);
            }

            $contract->deleted_by = auth()->id();
            $contract->save();
            $contract->delete();

            return $contract;
        });
    }

    public function restoreContract(Contract $contract): Contract
    {
        return DB::transaction(function () use ($contract) {
            $contract = Contract::withTrashed()
                ->lockForUpdate()
                ->findOrFail($contract->id);

            if (! $contract->trashed()) {
                throw ValidationException::withMessages([
                    'contract' => 'El contrato no está archivado.',
                ]);
            }

            if ($contract->status === ContractStatus::RESCINDIDO) {
                throw ValidationException::withMessages([
                    'contract' => 'Un contrato rescindido no puede restaurarse.',
                ]);
            }

            $lot = Lot::query()
                ->lockForUpdate()
                ->findOrFail($contract->lot_id);

            if ($contract->status === ContractStatus::PREVENTA_INACTIVA) {
                if ($lot->status !== LotStatus::DISPONIBLE) {
                    throw ValidationException::withMessages([
                        'lot_id' => 'El lote del contrato no está disponible para restaurarlo.',
                    ]);
                }

                $lot->update([
                    'status' => LotStatus::PREVENTA,
                    'updated_by' => auth()->id(),
                ]);
            }

            $contract->deleted_by = null;
            $contract->restore();

            return $contract->load([
                'customer',
                'customers',
                'lot',
                'lot.project',
            ]);
        });
    }

}
    
