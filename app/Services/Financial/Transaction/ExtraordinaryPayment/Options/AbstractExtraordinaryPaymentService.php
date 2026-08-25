<?php

namespace App\Services\Financial\Transaction\ExtraordinaryPayment\Options;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;

abstract class AbstractExtraordinaryPaymentService
{
    protected function alreadyApplied(AmortizationInstallment $installment, string $surplusAmount): bool
    {
        $storedExtra = (string) ($installment->extra_payment ?? '0.00');

        return bccomp($storedExtra, $surplusAmount, 2) === 0;
    }

    protected function processBasePayment(
        Contract $contract,
        AmortizationInstallment $installment,
        string $surplusAmount
    ): AmortizationInstallment {
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
            $startingBalance = round(
                (float) ($contract->sale_price ?? 0) - (float) ($contract->down_payment_pactada ?? 0),
                2
            );
        }

        $interest = (float) ($installment->interest_value ?? 0);

        if ($interest <= 0) {
            $interest = round($startingBalance * ((float) ($contract->interest_rate ?? 0) / 100), 2);
        }

        $regularPrincipal = round((float) ($installment->installment_value ?? 0) - $interest, 2);
        $availableForExtraPayment = round(max(0.0, $startingBalance - $regularPrincipal), 2);
        $effectiveSurplus = round(min((float) $surplusAmount, $availableForExtraPayment), 2);
        $montoPagadoTotal = round((float) ($installment->installment_value ?? 0) + $effectiveSurplus, 2);
        $amortizacion = round(max(0.0, $montoPagadoTotal - $interest), 2);
        $newBalance = round(max(0.0, $startingBalance - $amortizacion), 2);

        $installment->update([
            'extra_payment' => $effectiveSurplus,
            'interest_value' => $interest,
            'principal_value' => $amortizacion,
            'remaining_balance' => $newBalance,
            'projected_balance' => $newBalance,
            'status' => AmortizationStatus::PAID->value,
            'payment_date' => now(),
        ]);

        return $installment->fresh();
    }
}
