<?php

namespace App\Services\Financial\Transaction\ExtraordinaryPayment\Options;

use App\Models\AmortizationInstallment;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;

class TermReductionService
{
    public function apply(Contract $contract, AmortizationInstallment $installment, string $surplusAmount): AmortizationInstallment
    {
        if ($this->alreadyApplied($installment, $surplusAmount)) {
            return $installment->fresh();
        }

        $projectedBalance = (float) ($installment->projected_balance ?? $installment->remaining_balance ?? 0);
        $regularPrincipal = round((float) ($installment->installment_value ?? 0) - (float) ($installment->interest_value ?? 0), 2);
        $totalPrincipalPaid = round($regularPrincipal + (float) $surplusAmount, 2);
        $newBalance = round($projectedBalance - $totalPrincipalPaid, 2);

        $installment->update([
            'extra_payment' => $surplusAmount,
            'principal_value' => $totalPrincipalPaid,
            'remaining_balance' => $newBalance,
            'projected_balance' => $newBalance,
            'status' => 'paid',
            'payment_date' => now(),
        ]);

        $this->recalculateFuture($contract, $installment->fresh());

        return $installment->fresh();
    }

    public function recalculateFuture(Contract $contract, AmortizationInstallment $paidInstallment): void
    {
        DB::transaction(function () use ($contract, $paidInstallment) {
            $currentRemaining = max(0.0, (float) ($paidInstallment->remaining_balance ?? $paidInstallment->projected_balance ?? 0));
            $currentNumber = (int) ($paidInstallment->installment_number ?? 0);
            $rate = ((float) ($contract->interest_rate ?? 0)) / 100;

            $futureInstallments = $contract->amortizationInstallments()
                ->where('installment_number', '>', $currentNumber)
                ->orderBy('installment_number', 'asc')
                ->get();

            foreach ($futureInstallments as $futureInstallment) {
                if ($currentRemaining <= 0) {
                    $this->deleteRemainingFuture($contract, $futureInstallment->installment_number);
                    break;
                }

                $interest = round($currentRemaining * $rate, 2);
                $baseQuota = (float) ($futureInstallment->installment_value ?? 0);
                $principal = round(max(0.0, $baseQuota - $interest), 2);

                if ($currentRemaining < $principal) {
                    $principal = round($currentRemaining, 2);
                    $baseQuota = round($principal + $interest, 2);
                    $currentRemaining = 0.0;
                } else {
                    $currentRemaining = round($currentRemaining - $principal, 2);
                }

                $updated = [
                    'installment_value' => $baseQuota,
                    'interest_value' => $interest,
                    'principal_value' => $principal,
                    'quota_debt' => $baseQuota,
                    'projected_balance' => round(max(0.0, $currentRemaining + $principal), 2),
                    'remaining_balance' => round(max(0.0, $currentRemaining), 2),
                    'status' => 'pending',
                ];

                $futureInstallment->update($updated);

                if ($currentRemaining <= 0) {
                    $this->deleteRemainingFuture($contract, $futureInstallment->installment_number + 1);
                    break;
                }
            }
        });
    }

    private function deleteRemainingFuture(Contract $contract, int $fromInstallmentNumber): void
    {
        $contract->amortizationInstallments()
            ->where('installment_number', '>=', $fromInstallmentNumber)
            ->delete();
    }

    protected function alreadyApplied(AmortizationInstallment $installment, string $surplusAmount): bool
    {
        $storedExtra = (string) ($installment->extra_payment ?? '0.00');

        return bccomp($storedExtra, $surplusAmount, 2) === 0;
    }
}
