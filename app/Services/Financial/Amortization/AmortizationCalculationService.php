<?php

namespace App\Services\Financial\Amortization;

use App\Models\Contract;
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
            return $this->normalizeMoney(bcdiv($principal, (string) $months, 2));
        }

        $factor = bcadd('1.00', $monthlyRate, 10);
        $power = bcpow($factor, (string) $months, 10);
        $numerator = bcmul($principal, $monthlyRate, 10);
        $numerator = bcmul($numerator, $power, 10);
        $denominator = bcsub($power, '1.00', 10);

        return $this->normalizeMoney(bcdiv($numerator, $denominator, 10));
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
        $months = max(1, (int) $contract->term_months);
        $monthlyRate = bcdiv($this->normalizeMoney((string) $contract->interest_rate), '100', 10);
        $fixedQuota = $this->calculateFixedQuota($loanPrincipal, (string) $contract->interest_rate, $months);
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
            'status' => 'pending',
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
                'status' => 'pending',
            ];
        }

        return $schedule;
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
