<?php

namespace App\Services\Financial;

use App\Models\Contract;
use App\Models\AmortizationPlan;
use App\Enums\AmortizationStatus; // <-- Importamos el Enum
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class AmortizationService
{
    public function generateVersionOne(Contract $contract): array
    {
        if (AmortizationPlan::where('contract_id', $contract->id)->exists()) {
            throw ValidationException::withMessages(['contract' => 'Este contrato ya tiene una tabla de amortización generada.']);
        }

        return DB::transaction(function () use ($contract) {
            $installments = [];
            $startDate = Carbon::parse($contract->start_date);

            // Cuota Inicial (Installment 0)
            $principal = $contract->sale_price - $contract->down_payment_pactada;

            $installments[] = AmortizationPlan::create([
                'contract_id' => $contract->id,
                'version' => 1,
                'installment_number' => 0,
                'due_date' => $startDate->copy(),
                'installment_value' => $contract->down_payment_pactada,
                'principal_value' => $contract->down_payment_pactada,
                'interest_value' => 0,
                'remaining_balance' => $principal,
                'quota_debt' => $contract->down_payment_pactada,
                'status' => AmortizationStatus::UNPAID,
                'is_active' => true,
            ]);

            // Fórmula sistema francés
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
                    'due_date' => $startDate->copy()->addMonths($i),
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