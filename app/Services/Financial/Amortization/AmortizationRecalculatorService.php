<?php

namespace App\Services\Financial\Amortization;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationPlan;
use App\Models\Contract;
use Carbon\Carbon;

class AmortizationRecalculatorService
{
    public function applyExcess(
        Contract $contract,
        AmortizationPlan $currentPlan,
        string $excedente,
        ?string $paymentOption = null,
        ?string $baseRemainingBalance = null,
    ): void {
        if (bccomp($excedente, '0.00', 2) <= 0 || blank($paymentOption)) {
            return;
        }

        $normalizedOption = strtolower((string) $paymentOption);
        $normalizedOption = match ($normalizedOption) {
            'reduce_time', 'reducir_plazo' => 'reducir_plazo',
            'reduce_quota', 'reducir_cuota' => 'reducir_cuota',
            'transfer', 'adelantar_cuotas' => 'adelantar_cuotas',
            default => $normalizedOption,
        };

        if ($normalizedOption !== 'reducir_plazo') {
            return;
        }

        $this->rebuildReducedTermPlan($contract, $currentPlan, $excedente, $baseRemainingBalance);
    }

    protected function rebuildReducedTermPlan(
        Contract $contract,
        AmortizationPlan $currentPlan,
        string $excedente,
        ?string $baseRemainingBalance = null,
    ): void {
        $baseVersion = max(1, (int) ($currentPlan->version ?? 1));
        $newVersion = $baseVersion + 1;
        $currentInstallmentNumber = (int) ($currentPlan->installment_number ?? 0);

        $sourceBalance = blank($baseRemainingBalance)
            ? (string) ($currentPlan->remaining_balance ?? '0.00')
            : (string) $baseRemainingBalance;
        $updatedCurrentBalance = bcsub($sourceBalance, $excedente, 2);
        $updatedCurrentBalance = max('0.00', $updatedCurrentBalance);

        $historicRows = AmortizationPlan::where('contract_id', $contract->id)
            ->where('version', $baseVersion)
            ->where('installment_number', '<=', $currentInstallmentNumber)
            ->orderBy('installment_number', 'asc')
            ->get();

        $frozenRows = [];
        foreach ($historicRows as $row) {
            $payload = $row->toArray();
            unset($payload['id'], $payload['created_at'], $payload['updated_at']);
            $payload['contract_id'] = $contract->id;
            $payload['version'] = $newVersion;
            $payload['is_active'] = true;

            if ((int) $row->installment_number === $currentInstallmentNumber) {
                $payload['extra_payment'] = $excedente;
                $payload['remaining_balance'] = $updatedCurrentBalance;
                $payload['status'] = AmortizationStatus::PAID->value;
                $payload['quota_debt'] = '0.00';
            }

            $frozenRows[] = $payload;
        }

        AmortizationPlan::where('contract_id', $contract->id)
            ->where('version', $newVersion)
            ->delete();

        if ($frozenRows !== []) {
            AmortizationPlan::insert($frozenRows);
        }

        $runningBalance = $updatedCurrentBalance;
        $nextDueDate = $currentPlan->due_date ? Carbon::parse($currentPlan->due_date)->addMonthsNoOverflow(1) : now();
        $nextInstallmentNumber = $currentInstallmentNumber + 1;
        $monthlyRate = max(0.0, ((float) ($contract->interest_rate ?? 0)) / 100);
        $fixedQuota = (string) ($currentPlan->installment_value ?? '0.00');
        $futureRows = [];

        while (bccomp($runningBalance, '0.00', 2) > 0) {
            $interestValue = bcmul($runningBalance, (string) $monthlyRate, 2);
            $principalValue = bcsub($fixedQuota, $interestValue, 2);

            if (bccomp($principalValue, $runningBalance, 2) > 0) {
                $principalValue = $runningBalance;
                $installmentTotal = bcadd($principalValue, $interestValue, 2);
                $runningBalance = '0.00';

                $futureRows[] = [
                    'contract_id' => $contract->id,
                    'version' => $newVersion,
                    'installment_number' => $nextInstallmentNumber,
                    'due_date' => $nextDueDate->copy()->format('Y-m-d'),
                    'installment_value' => $installmentTotal,
                    'principal_value' => $principalValue,
                    'interest_value' => $interestValue,
                    'extra_payment' => '0.00',
                    'remaining_balance' => '0.00',
                    'interest_paid' => '0.00',
                    'principal_paid' => '0.00',
                    'quota_debt' => $installmentTotal,
                    'status' => AmortizationStatus::UNPAID->value,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                break;
            }

            $runningBalance = bcsub($runningBalance, $principalValue, 2);
            $futureRows[] = [
                'contract_id' => $contract->id,
                'version' => $newVersion,
                'installment_number' => $nextInstallmentNumber,
                'due_date' => $nextDueDate->copy()->format('Y-m-d'),
                'installment_value' => $fixedQuota,
                'principal_value' => $principalValue,
                'interest_value' => $interestValue,
                'extra_payment' => '0.00',
                'remaining_balance' => max('0.00', $runningBalance),
                'interest_paid' => '0.00',
                'principal_paid' => '0.00',
                'quota_debt' => $fixedQuota,
                'status' => AmortizationStatus::UNPAID->value,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (bccomp($runningBalance, '0.00', 2) <= 0) {
                break;
            }

            $nextInstallmentNumber++;
            $nextDueDate = $nextDueDate->copy()->addMonthsNoOverflow(1);
        }

        if ($futureRows !== []) {
            AmortizationPlan::insert($futureRows);
        }

        AmortizationPlan::where('contract_id', $contract->id)
            ->where('version', $baseVersion)
            ->update(['is_active' => false]);

        AmortizationPlan::where('contract_id', $contract->id)
            ->where('version', $newVersion)
            ->update(['is_active' => true]);
    }
}
