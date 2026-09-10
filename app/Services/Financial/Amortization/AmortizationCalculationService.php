<?php

namespace App\Services\Financial\Amortization;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Support\FinancialRules;
use Carbon\Carbon;

class AmortizationCalculationService
{
    public function calculateFixedQuota(string $principal, string $monthlyRatePercent, int $months): string
    {
        $principal = $this->normalizeMoney($principal);
        $monthlyRatePercent = $this->normalizeMoney($monthlyRatePercent);

        if ($months <= 0) {
            return '0.00';
        }

        $monthlyRate = bcdiv($monthlyRatePercent, '100', 10);

        if (bccomp($monthlyRate, '0.00', 10) === 0) {
            return FinancialRules::roundHalfUp2(bcdiv($principal, (string) $months, 10));
        }

        $factor = bcadd('1.00', $monthlyRate, 10);
        $power = bcpow($factor, (string) $months, 10);
        $numerator = bcmul($principal, $monthlyRate, 10);
        $numerator = bcmul($numerator, $power, 10);
        $denominator = bcsub($power, '1.00', 10);

        return FinancialRules::roundHalfUp2(bcdiv($numerator, $denominator, 10));
    }

    public function calculateInterest(
        string $balance,
        string $monthlyRatePercent
    ): string {
        $balance = $this->normalizeMoney($balance);
        $monthlyRatePercent = $this->normalizeMoney($monthlyRatePercent);

        $monthlyRate = bcdiv($monthlyRatePercent, '100', 10);

        return $this->normalizeMoney(
            bcmul($balance, $monthlyRate, 10)
        );
    }

    public function calculatePrincipal(
        string $installmentValue,
        string $interestValue
    ): string {
        $installmentValue = $this->normalizeMoney($installmentValue);
        $interestValue = $this->normalizeMoney($interestValue);

        return $this->normalizeMoney(
            bcsub($installmentValue, $interestValue, 10)
        );
    }

    public function calculateRemainingBalance(
        string $balance,
        string $principalValue
    ): string {
        $balance = $this->normalizeMoney($balance);
        $principalValue = $this->normalizeMoney($principalValue);

        $remainingBalance = bcsub(
            $balance,
            $principalValue,
            10
        );

        return $this->normalizeMoney(
            bccomp($remainingBalance, '0.00', 10) < 0
                ? '0.00'
                : $remainingBalance
        );
    }

    public function buildSchedule(Contract $contract): array
    {
        $loanPrincipal = bcsub($this->normalizeMoney((string) $contract->sale_price), $this->normalizeMoney((string) $contract->down_payment_pactada), 2);
        $months = max(0, (int) ($contract->term_months ?? 0));
        if ($contract->is_special_lot) {
            $months = 0;
        }
        $monthlyRate = bcdiv($this->normalizeMoney((string) $contract->interest_rate), '100', 10);
        $fixedQuota = $months > 0
            ? $this->calculateFixedQuota($loanPrincipal, (string) ($contract->interest_rate ?? '0'), $months)
            : '0.00';
        $balance = $loanPrincipal;
        $schedule = [];

        $schedule[] = [
            'installment_number' => 0,
            'due_date' => Carbon::parse($contract->start_date)->toDateString(),
            'installment_value' => $this->normalizeMoney((string) $contract->down_payment_pactada),
            'extra_payment' => '0.00',
            'interest_value' => '0.00',
            'principal_value' => $this->normalizeMoney((string) $contract->down_payment_pactada),
            'quota_debt' => $this->normalizeMoney((string) $contract->down_payment_pactada),
            'remaining_balance' => $loanPrincipal,
            'projected_balance' => $loanPrincipal,
            'status' => AmortizationStatus::PENDING->value,
        ];

        for ($index = 1; $index <= $months; $index++) {
            $interest = bccomp($monthlyRate, '0.00', 10) === 0
                ? '0.00'
                : bcmul($balance, $monthlyRate, 2);

            $principalPayment = bcsub($fixedQuota, $interest, 2);

            if ($index === $months) {
                $principalPayment = $balance;
                $fixedQuota = bcadd($principalPayment, $interest, 2);
                $balance = '0.00';
            } else {
                $balance = bccomp($balance, $principalPayment, 2) <= 0
                    ? '0.00'
                    : bcsub($balance, $principalPayment, 2);
            }

            $schedule[] = [
                'installment_number' => $index,
                'due_date' => $this->getDueDate($contract, $index),
                'installment_value' => $this->normalizeMoney($fixedQuota),
                'extra_payment' => '0.00',
                'interest_value' => $this->normalizeMoney($interest),
                'principal_value' => $this->normalizeMoney($principalPayment),
                'quota_debt' => $this->normalizeMoney($fixedQuota),
                'remaining_balance' => $this->normalizeMoney($balance),
                'projected_balance' => $this->normalizeMoney($balance),
                'status' => AmortizationStatus::PENDING->value,
            ];
        }

        return $schedule;
    }

    /**
     * Recalcula interés, capital y saldo de las cuotas posteriores a `$fromInstallmentNumber`
     * sobre el remaining_balance ya reducido. Conserva el PMT, los IDs y el número de filas.
     */
    public function recalculateFutureKeepingQuota(Contract $contract, int $fromInstallmentNumber): void
    {
        if ($fromInstallmentNumber <= 0) {
            return;
        }

        $from = $contract->amortizationInstallments()
            ->where('installment_number', $fromInstallmentNumber)
            ->first();
        if (! $from) {
            return;
        }

        $balance = $this->normalizeMoney((string) ($from->remaining_balance ?? '0.00'));
        $pmt = $this->normalizeMoney((string) ($from->installment_value ?? '0.00'));
        $ratePercent = (string) ($contract->interest_rate ?? '0');

        $future = $contract->amortizationInstallments()
            ->where('installment_number', '>', $fromInstallmentNumber)
            ->orderBy('installment_number')
            ->get();

        $remainingCount = $future->count();
        foreach ($future as $index => $row) {
            $isLast = $index === $remainingCount - 1;
            $this->recalculateKeepingQuotaRow($row, $balance, $pmt, $ratePercent, $isLast);
        }
    }

    private function recalculateKeepingQuotaRow(
        AmortizationInstallment $row,
        string &$balance,
        string $pmt,
        string $ratePercent,
        bool $isLast,
    ): void {
        if (bccomp($balance, '0.00', 2) <= 0) {
            $row->update([
                'interest_value' => '0.00',
                'principal_value' => '0.00',
                'remaining_balance' => '0.00',
                'projected_balance' => '0.00',
            ]);

            return;
        }

        $interest = $this->calculateInterest($balance, $ratePercent);

        if ($isLast) {
            $principal = $balance;
            $installmentValue = $this->normalizeMoney(bcadd($principal, $interest, 2));
            $newBalance = '0.00';
            $updates = [
                'installment_value' => $installmentValue,
                'interest_value' => $interest,
                'principal_value' => $this->normalizeMoney($principal),
                'remaining_balance' => $newBalance,
                'projected_balance' => $newBalance,
            ];
            if ($row->status !== AmortizationStatus::PAID) {
                $updates['quota_debt'] = $installmentValue;
            }
            $row->update($updates);
            $balance = $newBalance;

            return;
        }

        $principal = $this->calculatePrincipal($pmt, $interest);
        $newBalance = bccomp($balance, $principal, 2) <= 0
            ? '0.00'
            : $this->calculateRemainingBalance($balance, $principal);

        $row->update([
            'interest_value' => $interest,
            'principal_value' => $this->normalizeMoney($principal),
            'remaining_balance' => $newBalance,
            'projected_balance' => $newBalance,
        ]);
        $balance = $newBalance;
    }

    protected function getDueDate(Contract $contract, int $installmentNumber): string
    {
        $reference = $contract->first_installment_date
            ?? $contract->regular_payment_start_date
            ?? $contract->start_date;

        return Carbon::parse($reference)->addMonthsNoOverflow($installmentNumber - 1)->toDateString();
    }

    protected function normalizeMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
