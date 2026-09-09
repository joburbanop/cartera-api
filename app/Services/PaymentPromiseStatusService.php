<?php

namespace App\Services;

use App\Enums\AllocationTarget;
use App\Enums\PaymentPromiseStatusEnum;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\PaymentPromiseAllocation;
use App\Services\Collection\AllocationSourcePresenter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class PaymentPromiseStatusService
{
    public function __construct(
        private readonly AllocationSourcePresenter $sourcePresenter,
    ) {}

    public function decorate(Contract $contract, Collection $promises): Collection
    {
        $remainingPaid = $this->regularCollected($contract);
        $today = now()->startOfDay();

        $sorted = $promises
            ->sortBy([
                ['expected_date', 'asc'],
                ['payment_number', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        foreach ($sorted as $promise) {
            $expected = bcadd((string) ($promise->expected_amount ?? '0'), '0', 2);

            if (bccomp($remainingPaid, $expected, 2) >= 0) {
                $status = PaymentPromiseStatusEnum::PAGADA->value;
                $remainingAmount = '0.00';
                $remainingPaid = bcsub($remainingPaid, $expected, 2);
            } elseif (bccomp($remainingPaid, '0.00', 2) > 0) {
                $status = PaymentPromiseStatusEnum::PARCIAL->value;
                $remainingAmount = bcsub($expected, $remainingPaid, 2);
                $remainingPaid = '0.00';
            } else {
                $due = Carbon::parse((string) $promise->expected_date)->startOfDay();
                $status = $due->lt($today)
                    ? PaymentPromiseStatusEnum::VENCIDA->value
                    : PaymentPromiseStatusEnum::PENDIENTE->value;
                $remainingAmount = $expected;
            }

            $promise->setAttribute('status', $status);
            $promise->setAttribute('remaining_amount', $remainingAmount);
            $promise->setAttribute('is_paid', $status === PaymentPromiseStatusEnum::PAGADA->value);
        }

        $this->attachSources($sorted);

        return $sorted;
    }

    /**
     * Dinero que cuenta para el cronograma comercial: cuotas regulares y
     * abono a capital. La parte a inicial de un mixto no entra.
     */
    public function regularCollected(Contract $contract): string
    {
        if (! $contract->relationLoaded('transactions')) {
            $transactions = $contract->transactions()->with('allocations')->get();
        } else {
            $contract->loadMissing('transactions.allocations');
            $transactions = $contract->transactions;
        }

        $total = '0.00';

        foreach ($transactions as $tx) {
            $fromAllocations = '0.00';
            foreach ($tx->allocations as $allocation) {
                $target = $allocation->target instanceof AllocationTarget
                    ? $allocation->target
                    : AllocationTarget::tryFrom((string) $allocation->target);

                if ($target === AllocationTarget::INSTALLMENT || $target === AllocationTarget::CAPITAL) {
                    $fromAllocations = bcadd($fromAllocations, $this->money((string) $allocation->amount), 2);
                }
            }

            if (bccomp($fromAllocations, '0.00', 2) > 0) {
                $total = bcadd($total, $fromAllocations, 2);
                continue;
            }

            $type = $tx->transaction_type instanceof TransactionType
                ? $tx->transaction_type
                : TransactionType::tryFrom((string) $tx->transaction_type);

            if ($type !== TransactionType::DOWN_PAYMENT) {
                $total = bcadd($total, $this->money((string) $tx->amount), 2);
            }
        }

        return $this->money($total);
    }

    private function attachSources(Collection $promises): void
    {
        $ids = $promises->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        $grouped = PaymentPromiseAllocation::query()
            ->whereIn('payment_promise_id', $ids)
            ->with(['transaction.allocations.installment', 'transaction.promiseAllocations.promise'])
            ->orderBy('id')
            ->get()
            ->groupBy('payment_promise_id');

        foreach ($promises as $promise) {
            $sources = $this->sourcePresenter->forPromise(
                $grouped->get($promise->id) ?? collect(),
                (int) $promise->id,
            );
            $promise->setAttribute('sources', $sources);
            $expected = $this->money((string) ($promise->expected_amount ?? '0'));
            $remaining = $this->money((string) ($promise->remaining_amount ?? $expected));
            $promise->setAttribute('covered_amount', $this->money(bcsub($expected, $remaining, 2)));
        }
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
