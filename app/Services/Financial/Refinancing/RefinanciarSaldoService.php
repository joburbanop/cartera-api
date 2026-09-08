<?php

namespace App\Services\Financial\Refinancing;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RefinanciarSaldoService implements RefinanceStrategy
{
    public const ACTION_COBRAR_APARTE = 'cobrar_aparte';

    public const ACTION_CONDONAR = 'condonar';

    public function __construct(
        private readonly AmortizationCalculationService $calculationService,
    ) {}

    public function affectedInstallments(Contract $contract, array $params): Collection
    {
        $anchor = $this->anchorFor($contract);

        if (! $anchor) {
            return new Collection();
        }

        return $contract->amortizationInstallments()
            ->where('installment_number', '>=', (int) $anchor->installment_number)
            ->orderBy('installment_number')
            ->get();
    }

    public function apply(Contract $contract, array $params): void
    {
        $newSalePrice = $this->money($params['new_sale_price'] ?? null);
        $newTerm = (int) $params['new_term_months'];
        $newRate = bcadd((string) $params['new_interest_rate'], '0', 2);
        $action = (string) ($params['deferred_interest_action'] ?? '');

        if (bccomp($newSalePrice, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'new_sale_price' => 'El precio actualizado del lote debe ser mayor a cero.',
            ]);
        }

        $initial = $contract->amortizationInstallments()
            ->where('installment_number', 0)
            ->first();

        RefinancingGuards::assertInitialInstallmentIsClosed($initial);

        $anchor = $this->anchorFor($contract);

        if (! $anchor) {
            throw ValidationException::withMessages([
                'new_term_months' => 'No hay cuotas pendientes para refinanciar el saldo.',
            ]);
        }

        RefinancingGuards::assertAnchorIsNotPartiallyPaid($anchor);

        $toReplace = $this->affectedInstallments($contract, $params);
        $accruedUnpaidInterest = $this->accruedUnpaidInterestOf($toReplace);

        if (bccomp($accruedUnpaidInterest, '0.00', 2) > 0) {
            if (! in_array($action, [self::ACTION_COBRAR_APARTE, self::ACTION_CONDONAR], true)) {
                throw ValidationException::withMessages([
                    'deferred_interest_action' => 'Debe indicar si el interés causado no pagado se cobra aparte o se condona.',
                ]);
            }
        } else {
            $action = self::ACTION_CONDONAR;
        }

        $newDown = $this->sumPrincipalPaid($contract);
        $newPrincipal = bcsub($newSalePrice, $newDown, 2);

        if (bccomp($newPrincipal, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'new_sale_price' => sprintf(
                    'El capital neto a financiar sería %s (precio %s − capital pagado %s). '
                    .'Debe ser mayor a cero.',
                    number_format((float) $newPrincipal, 2, ',', '.'),
                    number_format((float) $newSalePrice, 2, ',', '.'),
                    number_format((float) $newDown, 2, ',', '.'),
                ),
            ]);
        }

        // Mismos helpers del motor; solo cambia el capital de entrada.
        $quota = $this->calculationService->calculateFixedQuota($newPrincipal, $newRate, $newTerm);
        $anchorNumber = (int) $anchor->installment_number;
        $dueDate = Carbon::parse((string) $anchor->due_date)->startOfDay();
        $hasReceiptNumber = Schema::hasColumn('amortization_installments', 'receipt_number');

        $contract->amortizationInstallments()
            ->where('installment_number', '>=', $anchorNumber)
            ->delete();

        $rows = [];
        $runningBalance = $newPrincipal;

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

        $currentDeferred = $this->money($contract->deferred_interest_balance ?? '0.00');
        $nextDeferred = $action === self::ACTION_COBRAR_APARTE
            ? bcadd($currentDeferred, $accruedUnpaidInterest, 2)
            : $currentDeferred;

        $contract->update([
            'sale_price' => $newSalePrice,
            'down_payment_pactada' => $newDown,
            'deferred_interest_balance' => $nextDeferred,
            'term_months' => ($anchorNumber - 1) + $newTerm,
            'interest_rate' => $newRate,
        ]);
    }

    /**
     * @param  Collection<int, AmortizationInstallment>  $installments
     */
    public function accruedUnpaidInterestOf(Collection $installments): string
    {
        $total = '0.00';

        foreach ($installments as $row) {
            $interest = $this->money($row->interest_value ?? '0');
            $paid = $this->money($row->interest_paid ?? '0');
            $unpaid = bcsub($interest, $paid, 2);
            if (bccomp($unpaid, '0.00', 2) > 0) {
                $total = bcadd($total, $unpaid, 2);
            }
        }

        return $total;
    }

    private function sumPrincipalPaid(Contract $contract): string
    {
        $total = '0.00';

        foreach ($contract->amortizationInstallments()->get() as $row) {
            $total = bcadd($total, $this->money($row->principal_paid ?? '0'), 2);
        }

        return $total;
    }

    private function anchorFor(Contract $contract): ?AmortizationInstallment
    {
        return $contract->amortizationInstallments()
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->where('installment_number', '>', 0)
            ->orderBy('installment_number')
            ->first();
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
    }
}
