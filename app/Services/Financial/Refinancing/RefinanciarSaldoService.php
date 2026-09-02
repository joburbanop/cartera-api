<?php

namespace App\Services\Financial\Refinancing;

use App\Enums\AmortizationStatus;
use App\Models\Contract;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RefinanciarSaldoService implements RefinanceStrategy
{
    public function __construct(
        private readonly AmortizationCalculationService $calculationService,
    ) {}

    public function apply(Contract $contract, array $params): void
    {
        $newTerm = (int) $params['new_term_months'];
        $newRate = bcadd((string) $params['new_interest_rate'], '0', 2);

        $anchor = $contract->amortizationInstallments()
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->where('installment_number', '>', 0)
            ->orderBy('installment_number')
            ->first();

        if (! $anchor) {
            throw ValidationException::withMessages([
                'new_term_months' => 'No hay cuotas pendientes para refinanciar el saldo.',
            ]);
        }

        $previous = $contract->amortizationInstallments()
            ->where('installment_number', (int) $anchor->installment_number - 1)
            ->first();

        $balance = $previous
            ? $this->money($previous->remaining_balance ?? $previous->projected_balance ?? '0.00')
            : $this->money(bcsub(
                $this->money($contract->sale_price ?? '0.00'),
                $this->money($contract->down_payment_pactada ?? '0.00'),
                2
            ));

        if (bccomp($balance, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'new_term_months' => 'El saldo pendiente es cero; no hay nada que refinanciar.',
            ]);
        }

        $quota = $this->calculationService->calculateFixedQuota($balance, $newRate, $newTerm);
        $anchorNumber = (int) $anchor->installment_number;
        $dueDate = Carbon::parse((string) $anchor->due_date)->startOfDay();
        $hasReceiptNumber = Schema::hasColumn('amortization_installments', 'receipt_number');

        $contract->amortizationInstallments()
            ->where('installment_number', '>=', $anchorNumber)
            ->delete();

        $rows = [];
        $runningBalance = $balance;

        for ($index = 1; $index <= $newTerm; $index++) {
            $interest = $this->calculationService->calculateInterest($runningBalance, $newRate);
            $installmentNumber = $anchorNumber + $index - 1;

            if ($index === $newTerm) {
                $principal = $runningBalance;
                $installmentValue = bcadd($principal, $interest, 2);
                $runningBalance = '0.00';
            } else {
                $principal = $this->calculationService->calculatePrincipal($quota, $interest);
                $installmentValue = $quota;
                $runningBalance = $this->calculationService->calculateRemainingBalance($runningBalance, $principal);
            }

            $row = [
                'contract_id' => $contract->id,
                'installment_number' => $installmentNumber,
                'due_date' => $dueDate->toDateString(),
                'payment_date' => null,
                'installment_value' => $this->money($installmentValue),
                'extra_payment' => '0.00',
                'interest_value' => $this->money($interest),
                'principal_value' => $this->money($principal),
                'interest_paid' => '0.00',
                'principal_paid' => '0.00',
                'quota_debt' => $this->money($installmentValue),
                'remaining_balance' => $this->money($runningBalance),
                'projected_balance' => $this->money($runningBalance),
                'status' => AmortizationStatus::PENDING->value,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($hasReceiptNumber) {
                $row['receipt_number'] = null;
            }

            $rows[] = $row;
            $dueDate = $dueDate->copy()->addMonthNoOverflow(1);
        }

        if ($rows !== []) {
            $contract->amortizationInstallments()->insert($rows);
        }

        $contract->update([
            'term_months' => ($anchorNumber - 1) + $newTerm,
            'interest_rate' => $newRate,
        ]);
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
    }
}
