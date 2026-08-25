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

        $previousInstallment = $contract->amortizationInstallments()
            ->where('installment_number', (int) ($installment->installment_number ?? 0) - 1)
            ->first();

        $startingBalance = $previousInstallment
            ? (float) ($previousInstallment->remaining_balance ?? $previousInstallment->projected_balance ?? 0)
            : (float) ($installment->projected_balance ?? $installment->remaining_balance ?? 0);

        if ($startingBalance <= 0) {
            $startingBalance = round((float) ($contract->sale_price ?? 0) - (float) ($contract->down_payment_pactada ?? 0), 2);
        }

        $interest = (float) ($installment->interest_value ?? 0);
        if ($interest <= 0) {
            $interest = round($startingBalance * ((float) ($contract->interest_rate ?? 0) / 100), 2);
        }

        $regularPrincipal = round((float) ($installment->installment_value ?? 0) - $interest, 2);
        $totalPrincipalPaid = round($regularPrincipal + (float) $surplusAmount, 2);
        $newBalance = round($startingBalance - $totalPrincipalPaid, 2);

        $installment->update([
            'extra_payment' => $surplusAmount,
            'interest_value' => $interest,
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
        $this->recalculateFuturePlan($contract, $paidInstallment);
    }

    public function recalculateFuturePlan(Contract $contract, AmortizationInstallment $currentInstallment): void
    {
        DB::transaction(function () use ($contract, $currentInstallment) {
            $saldoAcumulado = round((float) ($currentInstallment->remaining_balance ?? $currentInstallment->projected_balance ?? 0), 2);
            $currentNumber = (int) ($currentInstallment->installment_number ?? 0);
            $rate = ((float) ($contract->interest_rate ?? 0)) / 100;

            $futureInstallments = $contract->amortizationInstallments()
                ->where('installment_number', '>', $currentNumber)
                ->orderBy('installment_number', 'asc')
                ->get();

            foreach ($futureInstallments as $nextInstallment) {
                if ($saldoAcumulado <= 0) {
                    $this->deleteRemainingFuture($contract, $nextInstallment->installment_number);
                    break;
                }

                $nuevoInteres = round($saldoAcumulado * $rate, 2);
                $valorCuota = (float) ($nextInstallment->installment_value ?? 0);
                $nuevoCapital = round(max(0.0, $valorCuota - $nuevoInteres), 2);

                if ($saldoAcumulado < $nuevoCapital) {
                    $nuevoCapital = round($saldoAcumulado, 2);
                    $valorCuota = round($nuevoCapital + $nuevoInteres, 2);
                    $saldoAcumulado = 0.0;
                } else {
                    $saldoAcumulado = round($saldoAcumulado - $nuevoCapital, 2);
                }

                $nextInstallment->update([
                    'installment_value' => $valorCuota,
                    'interest_value' => $nuevoInteres,
                    'principal_value' => $nuevoCapital,
                    'quota_debt' => $valorCuota,
                    'projected_balance' => round(max(0.0, $saldoAcumulado + $nuevoCapital), 2),
                    'remaining_balance' => round(max(0.0, $saldoAcumulado), 2),
                    'status' => 'pending',
                ]);

                if ($saldoAcumulado <= 0) {
                    $this->deleteRemainingFuture($contract, $nextInstallment->installment_number + 1);
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
