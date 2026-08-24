<?php

namespace App\Services\Financial\Transaction\ExtraordinaryPayment\Options;

use App\Models\AmortizationInstallment;
use App\Models\Contract;

class PaymentReductionService
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

        return $installment->fresh();
    }

    protected function alreadyApplied(AmortizationInstallment $installment, string $surplusAmount): bool
    {
        $storedExtra = (string) ($installment->extra_payment ?? '0.00');

        return bccomp($storedExtra, $surplusAmount, 2) === 0;
    }
}
