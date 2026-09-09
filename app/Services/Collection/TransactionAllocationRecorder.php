<?php

namespace App\Services\Collection;

use App\Enums\AllocationTarget;
use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Transaction;
use App\Models\TransactionAllocation;

/**
 * Persiste a dónde fue cada peso de un cobro. La suma de las filas de una
 * transacción (sin contar promesas) es lo que vio el banco.
 */
class TransactionAllocationRecorder
{
    /**
     * @return array<int, array{principal: string, interest: string, extra: string}>
     */
    public function snapshotRegulars(Contract $contract): array
    {
        return $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->get()
            ->mapWithKeys(fn (AmortizationInstallment $row) => [
                (int) $row->id => $this->snapshot($row),
            ])
            ->all();
    }

    public function recordDownPayment(
        Transaction $transaction,
        ?AmortizationInstallment $initial,
        string $amount,
        string $principal,
        string $interest = '0.00',
    ): TransactionAllocation {
        return TransactionAllocation::query()->create([
            'transaction_id' => $transaction->id,
            'target' => AllocationTarget::DOWN_PAYMENT,
            'amortization_installment_id' => $initial?->id,
            'amount' => $this->money($amount),
            'principal' => $this->money($principal),
            'interest' => $this->money($interest),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $appliedInstallments
     * @param  array<int, array{principal: string, interest: string, extra: string}>  $before
     */
    public function recordAppliedInstallments(
        int $transactionId,
        array $appliedInstallments,
        array $before,
    ): void {
        foreach ($appliedInstallments as $applied) {
            $id = (int) ($applied['installment_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $row = AmortizationInstallment::query()->find($id);
            $delta = $this->delta($before[$id] ?? null, $row);

            TransactionAllocation::recordInstallmentAndCapital(
                $transactionId,
                $id,
                $this->money((string) ($applied['amount_applied'] ?? '0.00')),
                $delta['principal'],
                $delta['interest'],
                $delta['extra'],
            );
        }
    }

    /** @return array{principal: string, interest: string, extra: string} */
    public function snapshot(?AmortizationInstallment $row): array
    {
        return [
            'principal' => $this->money((string) ($row->principal_paid ?? '0.00')),
            'interest' => $this->money((string) ($row->interest_paid ?? '0.00')),
            'extra' => $this->money((string) ($row->extra_payment ?? '0.00')),
        ];
    }

    /**
     * @param  array{principal: string, interest: string, extra: string}|null  $before
     * @return array{principal: string, interest: string, extra: string}
     */
    public function delta(?array $before, ?AmortizationInstallment $after): array
    {
        $now = $this->snapshot($after);
        $was = $before ?? ['principal' => '0.00', 'interest' => '0.00', 'extra' => '0.00'];

        return [
            'principal' => $this->nonNegative(bcsub($now['principal'], $was['principal'], 2)),
            'interest' => $this->nonNegative(bcsub($now['interest'], $was['interest'], 2)),
            'extra' => $this->nonNegative(bcsub($now['extra'], $was['extra'], 2)),
        ];
    }

    private function nonNegative(string $value): string
    {
        return bccomp($value, '0.00', 2) > 0 ? $value : '0.00';
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
