<?php

namespace App\Services\Financial;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationPlan;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;

class AmortizationRecalculatorService
{
    public function applyExcess(Contract $contract, AmortizationPlan $currentPlan, string $excedente, ?string $paymentOption = null): void
    {
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

        $this->rebuildReducedTermPlan($contract, $currentPlan);
    }

    protected function rebuildReducedTermPlan(Contract $contract, AmortizationPlan $currentPlan): void
    {
        $remainingBalance = (string) ($currentPlan->remaining_balance ?? '0.00');

        if (bccomp($remainingBalance, '0.00', 2) <= 0) {
            return;
        }

        $contractRate = (float) ($contract->interest_rate ?? 0);
        $monthlyRate = max(0.0, $contractRate / 100);
        $installmentValue = (string) ($currentPlan->installment_value ?? '0.00');
        $currentVersion = max(1, (int) ($currentPlan->version ?? 1));
        $newVersion = $currentVersion + 1;
        $dueDate = $currentPlan->due_date ? $currentPlan->due_date->copy() : now();

        AmortizationPlan::where('contract_id', $contract->id)
            ->where('version', $newVersion)
            ->delete();

        $drafts = [];
        $runningBalance = $remainingBalance;
        $monthIndex = 1;

        while (bccomp($runningBalance, '0.00', 2) > 0) {
            $interestValue = bcmul($runningBalance, (string) $monthlyRate, 2);
            $principalValue = bcsub($installmentValue, $interestValue, 2);

            if (bccomp($principalValue, $runningBalance, 2) > 0) {
                $principalValue = $runningBalance;
                $installmentTotal = bcadd($principalValue, $interestValue, 2);
                $runningBalance = '0.00';

                $drafts[] = [
                    'contract_id' => $contract->id,
                    'version' => $newVersion,
                    'installment_number' => $currentPlan->installment_number + $monthIndex,
                    'due_date' => $dueDate->copy()->addMonthsNoOverflow($monthIndex),
                    'installment_value' => number_format((float) $installmentTotal, 2, '.', ''),
                    'principal_value' => number_format((float) $principalValue, 2, '.', ''),
                    'interest_value' => number_format((float) $interestValue, 2, '.', ''),
                    'remaining_balance' => '0.00',
                    'interest_paid' => '0.00',
                    'principal_paid' => '0.00',
                    'quota_debt' => number_format((float) $installmentTotal, 2, '.', ''),
                    'status' => AmortizationStatus::UNPAID,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                break;
            }

            $runningBalance = bcsub($runningBalance, $principalValue, 2);
            $drafts[] = [
                'contract_id' => $contract->id,
                'version' => $newVersion,
                'installment_number' => $currentPlan->installment_number + $monthIndex,
                'due_date' => $dueDate->copy()->addMonthsNoOverflow($monthIndex),
                'installment_value' => number_format((float) $installmentValue, 2, '.', ''),
                'principal_value' => number_format((float) $principalValue, 2, '.', ''),
                'interest_value' => number_format((float) $interestValue, 2, '.', ''),
                'remaining_balance' => max('0.00', $runningBalance),
                'interest_paid' => '0.00',
                'principal_paid' => '0.00',
                'quota_debt' => number_format((float) $installmentValue, 2, '.', ''),
                'status' => AmortizationStatus::UNPAID,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $monthIndex++;
        }

        if ($drafts !== []) {
            AmortizationPlan::where('contract_id', $contract->id)->update(['is_active' => false]);
            AmortizationPlan::insert($drafts);
            AmortizationPlan::where('contract_id', $contract->id)
                ->where('version', $newVersion)
                ->update(['is_active' => true]);
        }
    }
}
