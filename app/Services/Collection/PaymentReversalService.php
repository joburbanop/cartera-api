<?php

declare(strict_types=1);

namespace App\Services\Collection;

use App\Enums\AllocationTarget;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentReversalReason;
use App\Enums\ResidualBalanceStatus;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\ContractResidualBalance;
use App\Models\Lot;
use App\Models\PaymentPromiseAllocation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Financial\Refinancing\RefinanceContractService;
use App\Services\Financial\Transaction\InstallmentPaymentAllocator;
use App\Support\ContractFinancialLock;
use App\Support\DownPaymentLedger;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

class PaymentReversalService
{
    public const NOT_LAST = 'Solo se puede revertir el último cobro del contrato. Revierte primero los pagos posteriores.';

    public const LATER_REFINANCE = 'No se puede revertir este pago porque hay una refinanciación posterior.';

    public const PLAN_REWRITE = 'Este pago recálculó el plan (reducir plazo, reducir cuota o abono a capital) y no se puede revertir sin un snapshot.';

    public const NO_ALLOCATIONS = 'Este pago no tiene un ledger de imputación y no se puede revertir por esta vía.';

    public const SAN_MIGUEL = 'Los pagos de importación histórica de San Miguel sin comprobante no se pueden revertir por esta vía.';

    public const UNSUPPORTED_TYPE = 'Este tipo de transacción no se puede revertir.';

    public const ALREADY_REVERSED = 'Este pago ya fue revertido.';

    public const NOT_FOUND = 'La transacción no pertenece a este contrato.';

    public const SM_SELLER = 'Importación histórica San Miguel';

    /** @var list<string> */
    private const PLAN_REWRITE_OPTIONS = ['reducir_plazo', 'reducir_cuota', 'abono_capital'];

    public function __construct(
        private readonly InstallmentPaymentAllocator $allocator,
    ) {}

    /**
     * @return array{
     *     reversal_id: int,
     *     reversed_transaction_ids: list<int>,
     *     amount: string
     * }
     */
    public function reverse(
        int $contractId,
        int $transactionId,
        PaymentReversalReason $reason,
        ?string $notes,
        User $actor,
    ): array {
        return DB::transaction(function () use ($contractId, $transactionId, $reason, $notes, $actor) {
            $contract = ContractFinancialLock::acquire($contractId);
            $contract->load('lot');
            $requested = Transaction::query()
                ->with(['allocations', 'receipt'])
                ->where('contract_id', $contract->id)
                ->where('id', $transactionId)
                ->lockForUpdate()
                ->first();

            if (! $requested) {
                throw ValidationException::withMessages(['transaction' => self::NOT_FOUND]);
            }

            if ($requested->isReversed() || $requested->isReversal()) {
                throw ValidationException::withMessages(['transaction' => self::ALREADY_REVERSED]);
            }

            $event = $this->lastCollectionEvent($contract);
            $eventIds = $event->pluck('id')->map(fn ($id) => (int) $id)->all();

            if (! in_array((int) $requested->id, $eventIds, true)) {
                throw ValidationException::withMessages(['transaction' => self::NOT_LAST]);
            }

            $event = Transaction::query()
                ->with(['allocations', 'receipt'])
                ->whereIn('id', $eventIds)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            $this->assertEventReversible($contract, $event);

            foreach ($event as $original) {
                $this->undoOriginal($contract, $original);
            }

            $amount = $event->reduce(
                fn (string $carry, Transaction $tx) => bcadd($carry, $this->money((string) $tx->amount), 2),
                '0.00',
            );
            $ids = $event->pluck('id')->all();
            $first = $event->first();

            $reversal = Transaction::query()->create([
                'contract_id' => $contract->id,
                'transaction_type' => TransactionType::PAYMENT_REVERSAL,
                'amount' => $amount,
                'transaction_date' => Carbon::now()->toDateString(),
                'payment_method' => $first?->payment_method ?? PaymentMethod::CASH,
                'bank_account_id' => $first?->bank_account_id,
                'notes' => 'Reversa de pago #'.implode(', #', $ids),
                'receipt_number' => $first?->receipt_number,
                'reversal_reason' => $reason->value,
                'reversal_notes' => $reason === PaymentReversalReason::OTRO
                    ? trim((string) $notes)
                    : (filled($notes) ? trim((string) $notes) : null),
            ]);

            Transaction::query()
                ->whereIn('id', $ids)
                ->update([
                    'reversed_at' => now(),
                    'reversed_by' => $actor->id,
                    'reversal_transaction_id' => $reversal->id,
                ]);

            PaymentPromiseAllocation::query()
                ->whereIn('transaction_id', $ids)
                ->delete();

            $this->deactivateContractIfInitialReopened($contract, $event);

            return [
                'reversal_id' => (int) $reversal->id,
                'reversed_transaction_ids' => array_map('intval', $ids),
                'amount' => $amount,
            ];
        });
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function lastCollectionEvent(Contract $contract): Collection
    {
        $open = Transaction::query()
            ->with(['receipt', 'allocations'])
            ->where('contract_id', $contract->id)
            ->notReversed()
            ->collections()
            ->orderByDesc('id')
            ->get();

        $last = $open->first();
        if (! $last) {
            return collect();
        }

        $mate = $open->get(1);
        if ($mate && $this->isPreventaCascadePair($mate, $last)) {
            return collect([$mate, $last])->values();
        }

        return collect([$last]);
    }

    public function lastCollectionEventContains(Contract $contract, int $transactionId): bool
    {
        return $this->lastCollectionEvent($contract)
            ->contains(fn (Transaction $tx) => (int) $tx->id === $transactionId);
    }

    /**
     * @param  Collection<int, Transaction>  $event
     */
    public function eventWouldBeReversible(Contract $contract, Collection $event): bool
    {
        try {
            $this->assertEventReversible($contract, $event);
        } catch (ValidationException) {
            return false;
        }

        return $event->isNotEmpty();
    }

    /**
     * @param  Collection<int, Transaction>  $event
     */
    private function assertEventReversible(Contract $contract, Collection $event): void
    {
        if ($event->isEmpty()) {
            throw ValidationException::withMessages(['transaction' => self::NOT_LAST]);
        }

        $earliest = $event->min('created_at');
        if ($earliest && $this->hasLaterRefinance($contract, Carbon::parse((string) $earliest))) {
            throw ValidationException::withMessages(['transaction' => self::LATER_REFINANCE]);
        }

        foreach ($event as $tx) {
            $type = $tx->transaction_type instanceof TransactionType
                ? $tx->transaction_type
                : TransactionType::tryFrom((string) $tx->transaction_type);

            if (in_array($type, [
                TransactionType::EXTRAORDINARY_PAYMENT,
                TransactionType::REFUND,
                TransactionType::DEFERRED_INTEREST,
                TransactionType::PAYMENT_REVERSAL,
            ], true)) {
                throw ValidationException::withMessages(['transaction' => self::UNSUPPORTED_TYPE]);
            }

            $option = strtolower(trim((string) $tx->payment_option));
            if (in_array($option, self::PLAN_REWRITE_OPTIONS, true)) {
                throw ValidationException::withMessages(['transaction' => self::PLAN_REWRITE]);
            }

            if ($this->isSanMiguelUnreceipted($contract, $tx)) {
                throw ValidationException::withMessages(['transaction' => self::SAN_MIGUEL]);
            }

            $needsLedger = in_array($type, [
                TransactionType::REGULAR_PAYMENT,
                TransactionType::DOWN_PAYMENT,
                TransactionType::SPLIT_PAYMENT,
            ], true);

            if ($needsLedger && $tx->allocations->isEmpty()) {
                throw ValidationException::withMessages(['transaction' => self::NO_ALLOCATIONS]);
            }
        }
    }

    private function isPreventaCascadePair(Transaction $earlier, Transaction $later): bool
    {
        $earlierType = $earlier->transaction_type instanceof TransactionType
            ? $earlier->transaction_type
            : TransactionType::tryFrom((string) $earlier->transaction_type);
        $laterType = $later->transaction_type instanceof TransactionType
            ? $later->transaction_type
            : TransactionType::tryFrom((string) $later->transaction_type);

        if ($earlierType !== TransactionType::DOWN_PAYMENT || $laterType !== TransactionType::REGULAR_PAYMENT) {
            return false;
        }

        $dateA = optional($earlier->transaction_date)?->toDateString();
        $dateB = optional($later->transaction_date)?->toDateString();
        if ($dateA === null || $dateA !== $dateB) {
            return false;
        }

        $receiptA = trim((string) $earlier->receipt_number);
        $receiptB = trim((string) $later->receipt_number);
        if ($receiptA !== '' && $receiptA === $receiptB) {
            return true;
        }

        $pathA = (string) ($earlier->receipt?->file_path ?? '');
        $pathB = (string) ($later->receipt?->file_path ?? '');

        return $pathA !== '' && $pathA === $pathB;
    }

    private function isSanMiguelUnreceipted(Contract $contract, Transaction $tx): bool
    {
        if (trim((string) $contract->seller_name) !== self::SM_SELLER) {
            return false;
        }

        return $tx->receipt === null;
    }

    private function hasLaterRefinance(Contract $contract, Carbon $after): bool
    {
        return Activity::query()
            ->where('log_name', RefinanceContractService::LOG_NAME)
            ->where('subject_type', $contract->getMorphClass())
            ->where('subject_id', $contract->id)
            ->where('created_at', '>', $after)
            ->exists();
    }

    private function undoOriginal(Contract $contract, Transaction $original): void
    {
        $type = $original->transaction_type instanceof TransactionType
            ? $original->transaction_type
            : TransactionType::tryFrom((string) $original->transaction_type);

        if ($type === TransactionType::RESIDUAL_COLLECTION) {
            $this->undoResidualCollection($original);
            return;
        }

        $this->undoAllocations($contract, $original);
    }

    private function undoResidualCollection(Transaction $original): void
    {
        ContractResidualBalance::query()
            ->where('collected_transaction_id', $original->id)
            ->get()
            ->each(function (ContractResidualBalance $row) {
                $row->update([
                    'status' => ResidualBalanceStatus::PENDIENTE,
                    'collected_transaction_id' => null,
                    'collected_at' => null,
                ]);
            });

        ContractResidualBalance::query()
            ->where('last_partial_transaction_id', $original->id)
            ->get()
            ->each(function (ContractResidualBalance $row) {
                $partial = $this->money((string) ($row->last_partial_amount ?? '0.00'));
                $row->update([
                    'amount' => $this->money(bcadd($this->money((string) $row->amount), $partial, 2)),
                    'last_partial_transaction_id' => null,
                    'last_partial_amount' => null,
                ]);
            });
    }

    private function undoAllocations(Contract $contract, Transaction $original): void
    {
        $touched = [];

        foreach ($original->allocations as $allocation) {
            $installmentId = $allocation->amortization_installment_id
                ? (int) $allocation->amortization_installment_id
                : null;
            if (! $installmentId) {
                continue;
            }

            $installment = AmortizationInstallment::query()->find($installmentId);
            if (! $installment) {
                continue;
            }

            $principal = $this->money((string) $allocation->principal);
            $interest = $this->money((string) $allocation->interest);
            $applied = $this->money((string) $allocation->amount);

            $installment->principal_paid = $this->maxZero(bcsub(
                $this->money((string) $installment->principal_paid),
                $principal,
                2,
            ));
            $installment->interest_paid = $this->maxZero(bcsub(
                $this->money((string) $installment->interest_paid),
                $interest,
                2,
            ));
            $installment->quota_debt = $this->money(bcadd(
                $this->money((string) $installment->quota_debt),
                $applied,
                2,
            ));

            $target = $allocation->target instanceof AllocationTarget
                ? $allocation->target
                : AllocationTarget::tryFrom((string) $allocation->target);
            if ($target === AllocationTarget::CAPITAL) {
                $installment->extra_payment = $this->maxZero(bcsub(
                    $this->money((string) $installment->extra_payment),
                    $applied,
                    2,
                ));
            }

            $installment->save();
            $touched[$installmentId] = $installment->fresh();
        }

        foreach ($touched as $installment) {
            $this->restorePendingResidual($installment);
            $this->recomputeInstallmentStatus($contract, $installment->fresh());
        }
    }

    private function restorePendingResidual(AmortizationInstallment $installment): void
    {
        $residual = ContractResidualBalance::query()
            ->where('amortization_installment_id', $installment->id)
            ->where('status', ResidualBalanceStatus::PENDIENTE)
            ->first();

        if (! $residual) {
            return;
        }

        $installment->quota_debt = $this->money(bcadd(
            $this->money((string) $installment->quota_debt),
            $this->money((string) $residual->amount),
            2,
        ));
        $installment->save();
        $residual->delete();
    }

    private function recomputeInstallmentStatus(Contract $contract, AmortizationInstallment $installment): void
    {
        $debt = $this->money((string) $installment->quota_debt);

        if (bccomp($debt, '0.00', 2) <= 0) {
            $installment->update([
                'quota_debt' => '0.00',
                'status' => AmortizationStatus::PAID,
            ]);

            return;
        }

        $installment->update([
            'status' => $this->allocator->resolvePartialStatus($installment, $contract),
            'payment_date' => null,
        ]);
    }

    /**
     * @param  Collection<int, Transaction>  $event
     */
    private function deactivateContractIfInitialReopened(Contract $contract, Collection $event): void
    {
        $touchedInicial = $event->contains(function (Transaction $tx) {
            return $tx->allocations->contains(function ($allocation) {
                $target = $allocation->target instanceof AllocationTarget
                    ? $allocation->target
                    : AllocationTarget::tryFrom((string) $allocation->target);

                return $target === AllocationTarget::DOWN_PAYMENT;
            });
        });

        if (! $touchedInicial) {
            return;
        }

        $contract->refresh();
        if (DownPaymentLedger::isSettled($contract)) {
            return;
        }

        if ($contract->status === ContractStatus::ACTIVO) {
            $contract->update(['status' => ContractStatus::PREVENTA_INACTIVA]);
        }

        $lot = Lot::query()->find($contract->lot_id);
        if ($lot && $lot->status === LotStatus::VENDIDO) {
            $lot->update(['status' => LotStatus::PREVENTA]);
        }

        $initial = $contract->amortizationInstallments()
            ->where('installment_number', 0)
            ->first();
        if ($initial) {
            $this->recomputeInstallmentStatus($contract->fresh(), $initial);
        }
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function maxZero(string $value): string
    {
        return bccomp($value, '0.00', 2) < 0 ? '0.00' : $this->money($value);
    }
}
