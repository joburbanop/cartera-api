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
            ? $this->money($previousInstallment->remaining_balance ?? $previousInstallment->projected_balance ?? '0.00')
            : $this->money($installment->projected_balance ?? $installment->remaining_balance ?? '0.00');

        if (bccomp($startingBalance, '0.00', 2) <= 0) {
            $startingBalance = $this->money(bcsub(
                $this->money($contract->sale_price ?? '0.00'),
                $this->money($contract->down_payment_pactada ?? '0.00'),
                2
            ));
        }

        $interest = $this->money($installment->interest_value ?? '0.00');

        if (bccomp($interest, '0.00', 2) <= 0) {
            $rate = bcdiv($this->money($contract->interest_rate ?? '0'), '100', 10);
            $interest = $this->roundMoney(bcmul($startingBalance, $rate, 10));
        }

        $regularPrincipal = $this->roundMoney(bcsub($this->money($installment->installment_value ?? '0.00'), $interest, 10));
        $availableForExtraPayment = $this->maxMoney('0.00', bcsub($startingBalance, $regularPrincipal, 2));
        $effectiveSurplus = $this->roundMoney($this->minMoney($this->money($surplusAmount), $availableForExtraPayment));
        $montoPagadoTotal = $this->roundMoney(bcadd($this->money($installment->installment_value ?? '0.00'), $effectiveSurplus, 10));
        $amortizacion = $this->maxMoney('0.00', $this->roundMoney(bcsub($montoPagadoTotal, $interest, 10)));
        $newBalance = $this->maxMoney('0.00', $this->roundMoney(bcsub($startingBalance, $amortizacion, 10)));

        $installment->update([
            'extra_payment' => $effectiveSurplus,
            'interest_value' => $interest,
            'principal_value' => $amortizacion,
            'remaining_balance' => $newBalance,
            'projected_balance' => $newBalance,
            'status' => AmortizationStatus::PAID->value,
            'payment_date' => $installment->payment_date ?? $contract->transactions()->latest()->first()?->transaction_date ?? $contract->transactions()->latest()->first()?->created_at ?? now(),
        ]);

        return $installment->fresh();
    }

    protected function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return $this->roundMoney((string) $value);
    }

    protected function roundMoney(string $value): string
    {
        if (bccomp($value, '0', 12) >= 0) {
            return bcadd($value, '0.005', 2);
        }

        return bcsub($value, '0.005', 2);
    }

    protected function minMoney(string $left, string $right): string
    {
        return bccomp($left, $right, 2) <= 0 ? $left : $right;
    }

    protected function maxMoney(string $left, string $right): string
    {
        return bccomp($left, $right, 2) >= 0 ? $left : $right;
    }
}
