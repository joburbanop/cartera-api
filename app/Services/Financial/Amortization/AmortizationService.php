<?php

namespace App\Services\Financial\Amortization;

use App\Models\AmortizationInstallment;
use App\Models\AmortizationPlan;
use App\Models\AmortizationVersion;
use App\Models\Contract;
use App\Enums\AmortizationStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class AmortizationService
{
    private function roundMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    public function getRegularInstallmentDueDate(Contract $contract, int $installmentNumber): Carbon
    {
        $firstInstallmentDate = $contract->first_installment_date
            ?? $contract->regular_payment_start_date
            ?? $contract->start_date;

        return Carbon::parse($firstInstallmentDate)->addMonths($installmentNumber - 1);
    }

    public function generateVersionOne(Contract $contract): array
    {
        if (AmortizationPlan::where('contract_id', $contract->id)->exists()) {
            throw ValidationException::withMessages(['contract' => 'Este contrato ya tiene una tabla de amortización generada.']);
        }

        return DB::transaction(function () use ($contract) {
            $installments = [];
            $startDate = Carbon::parse($contract->start_date);

            $principal = $contract->sale_price - $contract->down_payment_pactada;

            $installments[] = AmortizationPlan::create([
                'contract_id' => $contract->id,
                'version' => 1,
                'installment_number' => 0,
                'due_date' => $startDate->copy(),
                'payment_date' => null,
                'installment_value' => $contract->down_payment_pactada,
                'principal_value' => $contract->down_payment_pactada,
                'interest_value' => 0,
                'remaining_balance' => $principal,
                'quota_debt' => $contract->down_payment_pactada,
                'status' => AmortizationStatus::UNPAID,
                'is_active' => true,
            ]);

            $rate = $contract->interest_rate / 100;
            $months = $contract->term_months;
            $balance = $principal;

            $fixedQuota = ($rate > 0)
                ? ($principal * $rate * pow(1 + $rate, $months)) / (pow(1 + $rate, $months) - 1)
                : ($principal / $months);

            for ($i = 1; $i <= $months; $i++) {
                $interest = $balance * $rate;
                $principalPayment = $fixedQuota - $interest;

                if ($i === $months) {
                    $principalPayment = $balance;
                    $fixedQuota = $principalPayment + $interest;
                    $balance = 0;
                } else {
                    $balance = max(0, $balance - $principalPayment);
                }

                $installments[] = AmortizationPlan::create([
                    'contract_id' => $contract->id,
                    'version' => 1,
                    'installment_number' => $i,
                    'due_date' => $this->getRegularInstallmentDueDate($contract, $i),
                    'payment_date' => null,
                    'installment_value' => round($fixedQuota, 2),
                    'principal_value' => round($principalPayment, 2),
                    'interest_value' => round($interest, 2),
                    'remaining_balance' => round(max(0, $balance), 2),
                    'quota_debt' => round($fixedQuota, 2),
                    'status' => AmortizationStatus::UNPAID,
                    'is_active' => true,
                ]);
            }

            return $installments;
        });
    }

    public function getActiveInstallments(Contract $contract): Collection
    {
        if ($contract->amortizationInstallments()->doesntExist()) {
            $this->generateInitialProjection($contract);
        }

        return $contract->amortizationInstallments()
            ->orderBy('installment_number', 'asc')
            ->get();
    }

    public function generateInitialProjection(Contract $contract): Collection
    {
        if ($contract->amortizationInstallments()->exists()) {
            return $contract->amortizationInstallments()->orderBy('installment_number', 'asc')->get();
        }

        return DB::transaction(function () use ($contract) {
            $startDate = Carbon::parse($contract->start_date);
            $principal = (float) $contract->sale_price - (float) $contract->down_payment_pactada;
            $rate = (float) $contract->interest_rate / 100;
            $months = (int) $contract->term_months;
            $balance = $principal;

            $contract->amortizationInstallments()->create([
                'installment_number' => 0,
                'due_date' => $startDate->copy(),
                'payment_date' => null,
                'installment_value' => (float) $contract->down_payment_pactada,
                'extra_payment' => 0,
                'interest_value' => 0,
                'principal_value' => (float) $contract->down_payment_pactada,
                'quota_debt' => (float) $contract->down_payment_pactada,
                'remaining_balance' => $principal,
                'projected_balance' => $principal,
                'status' => 'pending',
            ]);

            $fixedQuota = ($rate > 0)
                ? ($principal * $rate * pow(1 + $rate, $months)) / (pow(1 + $rate, $months) - 1)
                : ($principal / $months);

            for ($i = 1; $i <= $months; $i++) {
                $interest = $balance * $rate;
                $principalPayment = $fixedQuota - $interest;

                if ($i === $months) {
                    $principalPayment = $balance;
                    $fixedQuota = $principalPayment + $interest;
                    $balance = 0;
                } else {
                    $balance = max(0, $balance - $principalPayment);
                }

                $contract->amortizationInstallments()->create([
                    'installment_number' => $i,
                    'due_date' => $this->getRegularInstallmentDueDate($contract, $i),
                    'payment_date' => null,
                    'installment_value' => round($fixedQuota, 2),
                    'extra_payment' => 0,
                    'interest_value' => round($interest, 2),
                    'principal_value' => round($principalPayment, 2),
                    'quota_debt' => round($fixedQuota, 2),
                    'remaining_balance' => round(max(0, $balance), 2),
                    'projected_balance' => round(max(0, $balance), 2),
                    'status' => 'pending',
                ]);
            }

            return $contract->amortizationInstallments()->orderBy('installment_number', 'asc')->get();
        });
    }

    public function createReducedTermVersion(
        Contract $contract,
        AmortizationInstallment $currentInstallment,
        string $extraPayment,
        ?string $paymentOption = null,
    ): AmortizationInstallment {
        $normalizedOption = strtolower((string) ($paymentOption ?? ''));

        if (bccomp($extraPayment, '0.00', 2) <= 0 || ! in_array($normalizedOption, ['reducir_plazo', 'reduce_time'], true)) {
            return $currentInstallment;
        }

        $currentInstallmentNumber = (int) ($currentInstallment->installment_number ?? 0);
        $projectedBalance = (string) ($currentInstallment->projected_balance ?? $currentInstallment->remaining_balance ?? '0.00');
        $updatedCurrentBalance = max('0.00', bcsub($projectedBalance, $extraPayment, 2));

        $currentInstallment->update([
            'extra_payment' => $extraPayment,
            'principal_value' => round((float) ($currentInstallment->installment_value ?? 0) - (float) ($currentInstallment->interest_value ?? 0) + (float) $extraPayment, 2),
            'remaining_balance' => $updatedCurrentBalance,
            'projected_balance' => $updatedCurrentBalance,
            'payment_date' => now(),
            'status' => AmortizationStatus::PAID->value,
        ]);

        $runningBalance = $updatedCurrentBalance;
        $nextInstallmentNumber = $currentInstallmentNumber + 1;
        $nextDueDate = $currentInstallment->due_date
            ? Carbon::parse($currentInstallment->due_date)->addMonthsNoOverflow(1)
            : Carbon::parse($contract->first_installment_date ?? $contract->regular_payment_start_date ?? $contract->start_date)
                ->addMonthsNoOverflow($nextInstallmentNumber);

        foreach ($contract->amortizationInstallments()->where('installment_number', '>', $currentInstallmentNumber)->orderBy('installment_number', 'asc')->get() as $futureInstallment) {
            $interestValue = $this->roundMoney(bcmul($runningBalance, '0.01', 4));
            $baseInstallmentValue = (string) ($futureInstallment->installment_value ?? '0.00');
            $principalValue = $this->roundMoney(bcsub($baseInstallmentValue, $interestValue, 2));

            if (bccomp($principalValue, $runningBalance, 2) > 0) {
                $principalValue = $runningBalance;
            }

            $runningBalance = max('0.00', $this->roundMoney(bcsub($runningBalance, $principalValue, 2)));
            $installmentValue = $this->roundMoney(bcadd($principalValue, $interestValue, 2));

            $futureInstallment->update([
                'installment_value' => $installmentValue,
                'interest_value' => $interestValue,
                'principal_value' => $principalValue,
                'quota_debt' => $installmentValue,
                'remaining_balance' => $runningBalance,
                'projected_balance' => $runningBalance,
                'status' => bccomp($runningBalance, '0.00', 2) === 0 ? AmortizationStatus::PAID->value : AmortizationStatus::UNPAID->value,
            ]);
        }

        return $currentInstallment->fresh();
    }

    public function getAvailableVersions(Contract $contract): array
    {
        $versions = AmortizationPlan::where('contract_id', $contract->id)
            ->select('version')
            ->distinct()
            ->orderBy('version', 'asc')
            ->pluck('version')
            ->map(fn ($version) => (int) $version)
            ->values()
            ->all();

        return $versions;
    }

    public function getPlanByContract(Contract $contract, ?int $version = null)
    {
        $query = AmortizationPlan::where('contract_id', $contract->id);

        if ($version !== null) {
            $query->where('version', $version);
        } elseif (AmortizationPlan::where('contract_id', $contract->id)->where('is_active', true)->exists()) {
            $query->where('is_active', true);
        } else {
            $query->where('version', function ($subQuery) use ($contract) {
                $subQuery->selectRaw('MAX(version)')
                    ->from('amortization_plans')
                    ->where('contract_id', $contract->id);
            });
        }

        return $query->orderBy('installment_number', 'asc')->get();
    }
}
