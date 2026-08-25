<?php

namespace App\Services\Financial\Amortization;

use App\Enums\AmortizationStatusEnum;
use App\Models\Contract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Servicio centralizado para cálculos financieros de amortización.
 * 
 * Este servicio es la "Single Source of Truth" para todas las operaciones matemáticas
 * relacionadas con cuotas, intereses y saldos. Utiliza BC Math para precisión monetaria.
 */
final class AmortizationCalculationService
{
    /**
     * Calcula la cuota fija mensual usando la fórmula estándar de amortización.
     * 
     * @param string $principal Monto del préstamo
     * @param string $annualRatePercent Tasa de interés anual en porcentaje (ej. "12.5")
     * @param int $months Plazo en meses
     * @return string Cuota fija mensual formateada a 2 decimales
     */
    public function calculateFixedQuota(string $principal, string $annualRatePercent, int $months): string
    {
        $principal = $this->normalizeMoney($principal);
        $ratePercent = $this->normalizeMoney($annualRatePercent);
        
        if ($months <= 0) {
            return '0.00';
        }

        // Tasa mensual decimal
        $rate = bcdiv($ratePercent, '1200', 12); 

        if (bccomp($rate, '0.000000000000', 12) === 0) {
            return bcdiv($principal, (string) $months, 2);
        }

        // Fórmula: P * (r * (1+r)^n) / ((1+r)^n - 1)
        $pow = bcpow(bcadd('1.00', $rate, 12), (string) $months, 12);
        $numerator = bcmul($principal, bcmul($rate, $pow, 12), 12);
        $denominator = bcsub($pow, '1.00', 12);

        return bcdiv($numerator, $denominator, 2);
    }

    /**
     * Genera la tabla de amortización completa para un contrato.
     * 
     * @return Collection<int, array>
     */
    public function buildSchedule(Contract $contract): Collection
    {
        $salePrice = $this->normalizeMoney((string) $contract->sale_price);
        $downPayment = $this->normalizeMoney((string) $contract->down_payment_pactada);
        
        $principal = bcsub($salePrice, $downPayment, 2);
        
        $quota = $this->calculateFixedQuota(
            $principal,
            (string) $contract->interest_rate,
            (int) $contract->term_months
        );

        $balance = $principal;
        $rows = collect();
        $startDate = $this->getStartDate($contract);

        for ($i = 1; $i <= (int) $contract->term_months; $i++) {
            $monthlyRate = bcdiv((string) $contract->interest_rate, '1200', 12);
            $interest = bcmul($balance, $monthlyRate, 12);

            // Ajuste en la última cuota para cerrar saldo exactamente en 0
            if ($i === (int) $contract->term_months) {
                $principalPayment = $balance;
                $quotaForRow = bcadd($principalPayment, $interest, 2);
                $balance = '0.00';
            } else {
                $principalPayment = bcsub($quota, $interest, 2);
                $balance = bcsub($balance, $principalPayment, 2);
                $quotaForRow = $quota;
            }

            $dueDate = clone $startDate;
            $dueDate->addMonths($i - 1);
            
            // Ajuste de día si el mes no tiene ese día (ej. 31 en febrero)
            if ($dueDate->day !== $startDate->day) {
                $dueDate->endOfMonth();
            }

            $rows->push([
                'contract_id' => $contract->id,
                'installment_number' => $i,
                'due_date' => $dueDate->format('Y-m-d'),
                'installment_value' => $quotaForRow,
                'interest_value' => $this->normalizeMoney($interest),
                'principal_value' => $this->normalizeMoney($principalPayment),
                'quota_debt' => $quotaForRow,
                'remaining_balance' => max('0.00', $balance),
                'projected_balance' => max('0.00', $balance),
                'status' => AmortizationStatusEnum::PENDING->value,
                'extra_payment' => '0.00',
                'interest_paid' => '0.00',
                'principal_paid' => '0.00',
            ]);
        }

        return $rows;
    }

    /**
     * Aplica un pago a una cuota específica determinando el estado resultante.
     * 
     * @return array{amount_applied: string, remaining_balance: string, status: string, surplus?: string}
     */
    public function applyPayment(string $balanceDue, string $paymentAmount): array
    {
        $balanceDue = $this->normalizeMoney($balanceDue);
        $paymentAmount = $this->normalizeMoney($paymentAmount);

        $comparison = bccomp($paymentAmount, $balanceDue, 2);

        if ($comparison >= 0) {
            // Pago total o con excedente
            $surplus = bcsub($paymentAmount, $balanceDue, 2);
            return [
                'amount_applied' => $balanceDue,
                'remaining_balance' => '0.00',
                'status' => AmortizationStatusEnum::PAID->value,
                'surplus' => $surplus,
            ];
        }

        // Pago parcial
        $remaining = bcsub($balanceDue, $paymentAmount, 2);
        return [
            'amount_applied' => $paymentAmount,
            'remaining_balance' => $remaining,
            'status' => bccomp($remaining, '0.00', 2) === 0 
                ? AmortizationStatusEnum::PAID->value 
                : AmortizationStatusEnum::PARTIAL->value,
        ];
    }

    /**
     * Normaliza un valor numérico a string con 2 decimales seguros para BC Math.
     */
    public function normalizeMoney(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function getStartDate(Contract $contract): \Carbon\Carbon
    {
        $dateStr = $contract->first_installment_date 
            ?? $contract->regular_payment_start_date 
            ?? $contract->start_date 
            ?? now()->toDateString();
            
        return \Carbon\Carbon::parse($dateStr);
    }
}
