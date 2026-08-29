<?php

namespace App\Services\Financial\Amortization;

use App\Models\Contract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AmortizationService
{
    public function __construct(
        private readonly AmortizationCalculationService $amortizationCalculationService,
    ) {}

    public function getRegularInstallmentDueDate(Contract $contract, int $installmentNumber): Carbon
    {
        $firstInstallmentDate = $contract->first_installment_date
            ?? $contract->regular_payment_start_date
            ?? $contract->start_date;

        return Carbon::parse($firstInstallmentDate)->addMonths($installmentNumber - 1);
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
            $schedule = $this->amortizationCalculationService->buildSchedule($contract);

            foreach ($schedule as $row) {
                $contract->amortizationInstallments()->create([
                    'installment_number' => $row['installment_number'],
                    'due_date' => $row['due_date'],
                    'payment_date' => null,
                    'installment_value' => $row['installment_value'],
                    'extra_payment' => $row['extra_payment'],
                    'interest_value' => $row['interest_value'],
                    'principal_value' => $row['principal_value'],
                    'quota_debt' => $row['quota_debt'],
                    'remaining_balance' => $row['remaining_balance'],
                    'projected_balance' => $row['projected_balance'],
                    'status' => $row['status'],
                ]);
            }

            return $contract->amortizationInstallments()->orderBy('installment_number', 'asc')->get();
        });
    }
}
