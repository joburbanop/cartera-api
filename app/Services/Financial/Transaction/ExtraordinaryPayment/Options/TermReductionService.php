<?php

namespace App\Services\Financial\Transaction\ExtraordinaryPayment\Options;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TermReductionService extends AbstractExtraordinaryPaymentService
{
    public function apply(Contract $contract, AmortizationInstallment $installment, string $surplusAmount): AmortizationInstallment
    {
        if ($this->alreadyApplied($installment, $surplusAmount)) {
            return $installment->fresh();
        }

        $baseBalance = round((float) ($installment->remaining_balance ?? $installment->projected_balance ?? 0), 2);
        $surplus = max('0.00', $this->normalizeMoney($surplusAmount));
        $effectiveSurplus = min((float) $surplus, $baseBalance);
        $newCapital = round(max(0.0, $baseBalance - $effectiveSurplus), 2);
        $interestValue = round((float) ($installment->interest_value ?? 0), 2);
        $installmentValue = round((float) ($installment->installment_value ?? 0), 2);
        $principalValue = round(max(0.0, $installmentValue + $effectiveSurplus - $interestValue), 2);

        $installment->update([
            'extra_payment' => $this->normalizeMoney((string) $effectiveSurplus),
            'principal_value' => $principalValue,
            'remaining_balance' => $newCapital,
            'projected_balance' => $newCapital,
            'status' => AmortizationStatus::PAID->value,
            'payment_date' => $installment->payment_date ?? $contract->transactions()->latest()->first()?->transaction_date ?? $contract->transactions()->latest()->first()?->created_at ?? now(),
        ]);

        $this->recalculateFuture($contract, $installment->fresh());

        return $installment->fresh();
    }

    public function recalculateFuture(Contract $contract, AmortizationInstallment $paidInstallment): void
    {
        $this->recalculateFuturePlan($contract, $paidInstallment);
    }

    public function recalculateFuturePlan(Contract $contract, AmortizationInstallment $currentInstallment): void
    {
        DB::transaction(function () use ($contract, $currentInstallment) {
            $currentNumber = (int) ($currentInstallment->installment_number ?? 0);
            $balance = round((float) ($currentInstallment->remaining_balance ?? $currentInstallment->projected_balance ?? 0), 2);
            $pmt = max(0.0, (float) ($currentInstallment->installment_value ?? 0));
            $rate = ((float) ($contract->interest_rate ?? 0)) / 100;

            $this->deleteRemainingFuture($contract, $currentNumber + 1);

            if ($balance <= 0) {
                return;
            }

            $nextNumber = $currentNumber + 1;
            $dueDate = $currentInstallment->due_date
                ? Carbon::parse($currentInstallment->due_date)->addMonthNoOverflow(1)
                : now();
            $rows = [];

            while ($balance > 0) {
                $interest = round($balance * $rate, 2);
                $installmentValue = $pmt > 0 ? $pmt : round($balance + $interest, 2);

                if ($balance + $interest < $installmentValue) {
                    $installmentValue = round($balance + $interest, 2);
                    $amortization = round($balance, 2);
                    $balance = 0.0;
                } else {
                    $amortization = round(max(0.0, $installmentValue - $interest), 2);
                    $balance = round(max(0.0, $balance - $amortization), 2);
                }

                $row = [
                    'contract_id' => $contract->id,
                    'installment_number' => $nextNumber,
                    'due_date' => $dueDate->copy()->format('Y-m-d'),
                    'payment_date' => null,
                    'installment_value' => round($installmentValue, 2),
                    'extra_payment' => '0.00',
                    'interest_value' => round($interest, 2),
                    'principal_value' => round($amortization, 2),
                    'interest_paid' => '0.00',
                    'principal_paid' => '0.00',
                    'quota_debt' => round($installmentValue, 2),
                    'remaining_balance' => round(max(0.0, $balance), 2),
                    'projected_balance' => round(max(0.0, $balance), 2),
                    'status' => AmortizationStatus::UNPAID->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (Schema::hasColumn('amortization_installments', 'receipt_number')) {
                    $row['receipt_number'] = null;
                }

                if (Schema::hasColumn('amortization_installments', 'amortization_version_id')) {
                    $row['amortization_version_id'] = $currentInstallment->amortization_version_id ?? null;
                }

                $rows[] = $row;

                $nextNumber++;
                $dueDate = $dueDate->copy()->addMonthNoOverflow(1);

                if ($balance <= 0) {
                    break;
                }
            }

            if ($rows !== []) {
                $contract->amortizationInstallments()->insert($rows);
            }
        });
    }

    private function deleteRemainingFuture(Contract $contract, int $fromInstallmentNumber): void
    {
        $contract->amortizationInstallments()
            ->where('installment_number', '>=', $fromInstallmentNumber)
            ->delete();
    }

    private function normalizeMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
