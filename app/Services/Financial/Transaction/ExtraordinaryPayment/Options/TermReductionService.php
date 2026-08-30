<?php

namespace App\Services\Financial\Transaction\ExtraordinaryPayment\Options;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TermReductionService extends AbstractExtraordinaryPaymentService
{
    public function apply(Contract $contract, AmortizationInstallment $installment, string $surplusAmount): AmortizationInstallment
    {
        if ($this->alreadyApplied($installment, $surplusAmount)) {
            return $installment->fresh();
        }

        $baseBalance = $this->money($installment->remaining_balance ?? $installment->projected_balance ?? '0.00');
        $surplus = $this->maxMoney('0.00', $this->money($surplusAmount));
        $effectiveSurplus = $this->minMoney($surplus, $baseBalance);
        $newCapital = $this->maxMoney('0.00', bcsub($baseBalance, $effectiveSurplus, 2));
        $interestValue = $this->money($installment->interest_value ?? '0.00');
        $installmentValue = $this->money($installment->installment_value ?? '0.00');
        $principalValue = $this->maxMoney('0.00', bcsub(bcadd($installmentValue, $effectiveSurplus, 2), $interestValue, 2));

        $installment->update([
            'extra_payment' => $effectiveSurplus,
            'principal_value' => $principalValue,
            'remaining_balance' => $newCapital,
            'projected_balance' => $newCapital,
            'status' => AmortizationStatus::PAID->value,
            'payment_date' => $installment->payment_date ?? $contract->transactions()->latest()->first()?->transaction_date ?? $contract->transactions()->latest()->first()?->created_at ?? now(),
        ]);

        $this->recalculateFuture($contract, $installment->fresh());

        return $installment->fresh();
    }

    public function recalculateFuture(Contract $contract, AmortizationInstallment $paidInstallment): void
    {
        $this->recalculateFuturePlan($contract, $paidInstallment);
    }

    public function recalculateFuturePlan(Contract $contract, AmortizationInstallment $currentInstallment): void
    {
        DB::transaction(function () use ($contract, $currentInstallment) {
            $currentNumber = (int) ($currentInstallment->installment_number ?? 0);
            $balance = $this->money($currentInstallment->remaining_balance ?? $currentInstallment->projected_balance ?? '0.00');
            $pmt = $this->maxMoney('0.00', $this->money($currentInstallment->installment_value ?? '0.00'));
            $rate = bcdiv($this->money($contract->interest_rate ?? '0'), '100', 10);

            $this->deleteRemainingFuture($contract, $currentNumber + 1);

            if (bccomp($balance, '0.00', 2) <= 0) {
                return;
            }

            $nextNumber = $currentNumber + 1;
            $dueDate = $currentInstallment->due_date
                ? Carbon::parse($currentInstallment->due_date)->addMonthNoOverflow(1)
                : now();
            $rows = [];
            $hasReceiptNumber = Schema::hasColumn('amortization_installments', 'receipt_number');

            while (bccomp($balance, '0.00', 2) > 0) {
                $interest = $this->roundMoney(bcmul($balance, $rate, 10));
                $installmentValue = bccomp($pmt, '0.00', 2) > 0
                    ? $pmt
                    : $this->roundMoney(bcadd($balance, $interest, 10));

                if (bccomp(bcadd($balance, $interest, 10), $installmentValue, 10) < 0) {
                    $installmentValue = $this->roundMoney(bcadd($balance, $interest, 10));
                    $amortization = $this->money($balance);
                    $balance = '0.00';
                } else {
                    $amortization = $this->maxMoney('0.00', $this->roundMoney(bcsub($installmentValue, $interest, 10)));
                    $balance = $this->maxMoney('0.00', $this->roundMoney(bcsub($balance, $amortization, 10)));
                }

                $row = [
                    'contract_id' => $contract->id,
                    'installment_number' => $nextNumber,
                    'due_date' => $dueDate->copy()->format('Y-m-d'),
                    'payment_date' => null,
                    'installment_value' => $this->money($installmentValue),
                    'extra_payment' => '0.00',
                    'interest_value' => $this->money($interest),
                    'principal_value' => $this->money($amortization),
                    'interest_paid' => '0.00',
                    'principal_paid' => '0.00',
                    'quota_debt' => $this->money($installmentValue),
                    'remaining_balance' => $this->maxMoney('0.00', $this->money($balance)),
                    'projected_balance' => $this->maxMoney('0.00', $this->money($balance)),
                    'status' => AmortizationStatus::PENDING->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($hasReceiptNumber) {
                    $row['receipt_number'] = null;
                }

                $rows[] = $row;

                $nextNumber++;
                $dueDate = $dueDate->copy()->addMonthNoOverflow(1);

                if (bccomp($balance, '0.00', 2) <= 0) {
                    break;
                }
            }

            if ($rows !== []) {
                $contract->amortizationInstallments()->insert($rows);
            }
        });
    }

    private function deleteRemainingFuture(Contract $contract, int $fromInstallmentNumber): void
    {
        $contract->amortizationInstallments()
            ->where('installment_number', '>=', $fromInstallmentNumber)
            ->delete();
    }
}
