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
            $installments[] = AmortizationPlan::create([
                'contract_id' => $contract->id,
                'version' => 1,
                'installment_number' => 0,
                'due_date' => $startDate->copy(),
                'installment_value' => $contract->down_payment_pactada,
                'principal_value' => $contract->down_payment_pactada,
                'interest_value' => 0,
                'remaining_balance' => $contract->down_payment_pactada,
                'status' => AmortizationStatus::UNPAID,
            ]);

            // Motor Financiero para calcular las mensualidades
            $principal = $contract->sale_price - $contract->down_payment_pactada;
            $rate = $contract->interest_rate / 100;
            $months = $contract->term_months;
            $balance = $principal;

            $fixedQuota = ($rate > 0) 
                ? $principal * ($rate * pow(1 + $rate, $months)) / (pow(1 + $rate, $months) - 1)
                : $principal / $months;

            // Generar mensualidades
            for ($i = 1; $i <= $months; $i++) {
                $interest = $balance * $rate;
                $principalPayment = $fixedQuota - $interest;
                $balance -= $principalPayment;

                // Ajuste de última cuota
                if ($i == $months) {
                    $principalPayment += $balance; 
                    $fixedQuota = $principalPayment + $interest;
                    $balance = 0;
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
                    'status' => AmortizationStatus::UNPAID, 
                ]);
            }

            return $installments;
        });
    }


    public function getPlanByContract(Contract $contract)
    {
        return AmortizationPlan::where('contract_id', $contract->id)
            ->orderBy('installment_number', 'asc')
            ->get();
    }
}