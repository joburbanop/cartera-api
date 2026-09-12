<?php

namespace App\Services\Collection;

use App\Enums\AllocationTarget;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Support\ReceiptNumber;
use App\Models\PaymentPromiseAllocation;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Arma el desglose cuota → pagos para amortización y promesa.
 */
class AllocationSourcePresenter
{
    public function attachToInstallments(Collection $installments): void
    {
        $ids = $installments->pluck('id')->filter()->all();
        if ($ids === []) {
            return;
        }

        $grouped = TransactionAllocation::query()
            ->whereIn('amortization_installment_id', $ids)
            ->whereHas('transaction', fn ($query) => $this->constrainLiveCollection($query))
            ->with(['transaction.allocations.installment', 'installment'])
            ->orderBy('id')
            ->get()
            ->groupBy('amortization_installment_id');

        foreach ($installments as $installment) {
            if (! $installment instanceof AmortizationInstallment) {
                continue;
            }

            $sources = $this->forInstallment(
                $grouped->get($installment->id) ?? collect(),
                (int) $installment->id,
            );
            $installment->setAttribute('sources', $sources);

            $covered = $sources === []
                ? $this->money(bcadd(
                    (string) ($installment->interest_paid ?? '0'),
                    (string) ($installment->principal_paid ?? '0'),
                    2
                ))
                : $this->sumAmounts($sources);
            $installment->setAttribute('covered_amount', $covered);
        }
    }

    /**
     * @param  Collection<int, TransactionAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    public function forInstallment(Collection $allocations, int $installmentId): array
    {
        return $allocations
            ->map(function (TransactionAllocation $allocation) use ($installmentId) {
                $tx = $allocation->transaction;
                if (! $tx instanceof Transaction || ! $this->isLiveCollection($tx)) {
                    return null;
                }

                $isOrigin = $this->isOriginAllocation($tx, $installmentId);

                return [
                    'transaction_id' => $tx->id,
                    'transaction_date' => $tx->transaction_date?->toDateString(),
                    'receipt_number' => $this->receiptNumber($tx),
                    'amount' => $this->money((string) $allocation->amount),
                    'principal' => $this->money((string) $allocation->principal),
                    'interest' => $this->money((string) $allocation->interest),
                    'also_applied_to' => $isOrigin
                        ? $this->alsoAppliedFromAllocations($tx, $installmentId)
                        : [],
                    'came_from' => $isOrigin
                        ? []
                        : $this->cameFromOrigin($tx, $allocation),
                    'route' => $this->receiptRoute($tx),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PaymentPromiseAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    public function forPromise(Collection $allocations, int $promiseId): array
    {
        return $allocations
            ->map(function (PaymentPromiseAllocation $allocation) use ($promiseId) {
                $tx = $allocation->transaction;
                if (! $tx instanceof Transaction || ! $this->isLiveCollection($tx)) {
                    return null;
                }

                $isOrigin = $this->isOriginPromiseAllocation($tx, $promiseId);

                return [
                    'transaction_id' => $tx->id,
                    'transaction_date' => $tx->transaction_date?->toDateString(),
                    'receipt_number' => $this->receiptNumber($tx),
                    'amount' => $this->money((string) $allocation->amount),
                    'also_applied_to' => $isOrigin
                        ? $this->alsoAppliedFromPromise($tx, $promiseId)
                        : [],
                    'came_from' => $isOrigin
                        ? []
                        : $this->cameFromPromiseOrigin($tx, $allocation),
                    'route' => $this->promiseRoute($tx),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * El primer allocation del cobro es el origen del recibo; el resto es sobrante
     * que aterrizó en otras cuotas o en capital. Misma lista que el reparto HV.
     */
    private function isOriginAllocation(Transaction $tx, int $currentInstallmentId): bool
    {
        $origin = $this->originAllocation($tx);
        if (! $origin instanceof TransactionAllocation) {
            return true;
        }

        return (int) $origin->amortization_installment_id === $currentInstallmentId;
    }

    private function originAllocation(Transaction $tx): ?TransactionAllocation
    {
        $origin = $tx->allocations->sortBy('id')->first();

        return $origin instanceof TransactionAllocation ? $origin : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cameFromOrigin(Transaction $tx, TransactionAllocation $current): array
    {
        $origin = $this->originAllocation($tx);
        if (! $origin instanceof TransactionAllocation) {
            return [];
        }

        return [$this->allocationPeer($origin, $this->money((string) $current->amount))];
    }

    /**
     * @return array<string, mixed>
     */
    private function allocationPeer(TransactionAllocation $allocation, string $amount): array
    {
        $target = $allocation->target instanceof AllocationTarget
            ? $allocation->target
            : AllocationTarget::tryFrom((string) $allocation->target);

        $installmentNumber = $allocation->installment
            ? (int) $allocation->installment->installment_number
            : null;
        if ($installmentNumber === null && $target === AllocationTarget::DOWN_PAYMENT) {
            $installmentNumber = 0;
        }

        return [
            'target_label' => $target?->label() ?? (string) $allocation->target,
            'installment_number' => $installmentNumber,
            'amount' => $amount,
        ];
    }

    /**
     * Recorrido completo del recibo, en el orden en que se imputó.
     * No cambia montos: solo sirve para leer la trazabilidad.
     *
     * @return list<array<string, mixed>>
     */
    private function receiptRoute(Transaction $tx): array
    {
        return $tx->allocations
            ->sortBy('id')
            ->values()
            ->map(fn (TransactionAllocation $allocation) => $this->allocationPeer(
                $allocation,
                $this->money((string) $allocation->amount),
            ))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function promiseRoute(Transaction $tx): array
    {
        return $tx->promiseAllocations
            ->sortBy('id')
            ->values()
            ->map(fn (PaymentPromiseAllocation $allocation) => $this->promisePeer(
                $allocation,
                $this->money((string) $allocation->amount),
            ))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alsoAppliedFromAllocations(Transaction $tx, int $currentInstallmentId): array
    {
        return $tx->allocations
            ->filter(function ($allocation) use ($currentInstallmentId) {
                return (int) $allocation->amortization_installment_id !== $currentInstallmentId;
            })
            ->map(fn ($allocation) => $this->allocationPeer(
                $allocation,
                $this->money((string) $allocation->amount),
            ))
            ->values()
            ->all();
    }

    /**
     * Primer payment_promise_allocations del cobro = origen del recibo
     * en el cronograma comercial. El resto es sobrante que aterrizó en
     * otras promesas. No usa transaction_allocations.
     */
    private function isOriginPromiseAllocation(Transaction $tx, int $currentPromiseId): bool
    {
        $origin = $this->originPromiseAllocation($tx);
        if (! $origin instanceof PaymentPromiseAllocation) {
            return true;
        }

        return (int) $origin->payment_promise_id === $currentPromiseId;
    }

    private function originPromiseAllocation(Transaction $tx): ?PaymentPromiseAllocation
    {
        $origin = $tx->promiseAllocations->sortBy('id')->first();

        return $origin instanceof PaymentPromiseAllocation ? $origin : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cameFromPromiseOrigin(Transaction $tx, PaymentPromiseAllocation $current): array
    {
        $origin = $this->originPromiseAllocation($tx);
        if (! $origin instanceof PaymentPromiseAllocation) {
            return [];
        }

        return [$this->promisePeer($origin, $this->money((string) $current->amount))];
    }

    /**
     * @return array<string, mixed>
     */
    private function promisePeer(PaymentPromiseAllocation $allocation, string $amount): array
    {
        $number = $allocation->promise?->payment_number;

        return [
            'target_label' => $number ? 'Promesa #'.$number : 'Otra promesa',
            'installment_number' => $number !== null ? (int) $number : null,
            'amount' => $amount,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alsoAppliedFromPromise(Transaction $tx, int $currentPromiseId): array
    {
        $others = [];

        foreach ($tx->allocations as $allocation) {
            $target = $allocation->target instanceof AllocationTarget
                ? $allocation->target
                : AllocationTarget::tryFrom((string) $allocation->target);

            if ($target === AllocationTarget::DOWN_PAYMENT) {
                $others[] = [
                    'target_label' => AllocationTarget::DOWN_PAYMENT->label(),
                    'installment_number' => $allocation->installment
                        ? (int) $allocation->installment->installment_number
                        : 0,
                    'amount' => $this->money((string) $allocation->amount),
                ];
            }
        }

        foreach ($tx->promiseAllocations as $allocation) {
            if ((int) $allocation->payment_promise_id === $currentPromiseId) {
                continue;
            }

            $others[] = $this->promisePeer(
                $allocation,
                $this->money((string) $allocation->amount),
            );
        }

        return $others;
    }

    private function receiptNumber(Transaction $tx): ?string
    {
        return ReceiptNumber::fromStored($tx->receipt_number, $tx->notes);
    }

    /**
     * Un cobro revertido (o la fila de reversa) no cubre la cuota/promesa:
     * el par se anula y solo cuentan las transacciones vivas.
     */
    private function isLiveCollection(Transaction $tx): bool
    {
        if ($tx->isReversed()) {
            return false;
        }

        return ! $tx->isReversal();
    }

    private function constrainLiveCollection(Builder $query): Builder
    {
        return $query
            ->whereNull('reversed_at')
            ->where('transaction_type', '!=', TransactionType::PAYMENT_REVERSAL);
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     */
    private function sumAmounts(array $sources): string
    {
        $total = '0.00';
        foreach ($sources as $source) {
            $total = bcadd($total, $this->money((string) ($source['amount'] ?? '0')), 2);
        }

        return $this->money($total);
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
