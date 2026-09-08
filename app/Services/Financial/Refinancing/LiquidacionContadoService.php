<?php

namespace App\Services\Financial\Refinancing;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class LiquidacionContadoService implements RefinanceStrategy
{
    public function affectedInstallments(Contract $contract, array $params): Collection
    {
        return $this->unpaidInstallments($contract);
    }

    public function apply(Contract $contract, array $params): void
    {
        $unpaid = $this->unpaidInstallments($contract);

        if ($unpaid->isEmpty()) {
            throw ValidationException::withMessages([
                'tipo' => 'No hay saldo pendiente para liquidar de contado.',
            ]);
        }

        $breakdown = $this->breakdown($contract);

        if (
            bccomp($breakdown['amount_to_close'], '0.00', 2) <= 0
            && bccomp($breakdown['unaccrued_forgiven_interest'], '0.00', 2) <= 0
        ) {
            throw ValidationException::withMessages([
                'tipo' => 'No hay saldo pendiente para liquidar de contado.',
            ]);
        }

        $today = Carbon::today()->toDateString();

        foreach ($unpaid as $row) {
            $due = Carbon::parse((string) $row->due_date)->toDateString();
            $principal = $this->money($row->principal_value ?? '0');
            $interest = $this->money($row->interest_value ?? '0');

            if ($due > $today) {
                $interest = '0.00';
            }

            $installmentValue = bcadd($principal, $interest, 2);
            $paidTotal = bcadd(
                $this->money($row->interest_paid ?? '0'),
                $this->money($row->principal_paid ?? '0'),
                2
            );
            $debt = bcsub($installmentValue, $paidTotal, 2);

            $row->update([
                'interest_value' => $interest,
                'installment_value' => $installmentValue,
                'quota_debt' => bccomp($debt, '0.00', 2) < 0 ? '0.00' : $debt,
            ]);
        }
    }

    /**
     * @return array{
     *     outstanding_capital: string,
     *     accrued_unpaid_interest: string,
     *     unaccrued_forgiven_interest: string,
     *     amount_to_close: string,
     *     deferred_interest_balance: string
     * }
     */
    public function breakdown(Contract $contract): array
    {
        $today = Carbon::today()->toDateString();
        $capital = '0.00';
        $accrued = '0.00';
        $unaccrued = '0.00';

        foreach ($contract->amortizationInstallments()->orderBy('installment_number')->get() as $row) {
            // El capital sale del cronograma, no de sale_price: en los contratos con
            // abonos extra incorporados a la cuota ambas cifras no coinciden, y lo que
            // el cliente debe es lo que queda en la tabla.
            $capitalUnpaid = bcsub(
                $this->money($row->principal_value ?? '0'),
                $this->money($row->principal_paid ?? '0'),
                2
            );
            if (bccomp($capitalUnpaid, '0.00', 2) > 0) {
                $capital = bcadd($capital, $capitalUnpaid, 2);
            }

            if ($this->statusOf($row) === AmortizationStatus::PAID->value) {
                continue;
            }

            $interestUnpaid = bcsub(
                $this->money($row->interest_value ?? '0'),
                $this->money($row->interest_paid ?? '0'),
                2
            );
            if (bccomp($interestUnpaid, '0.00', 2) < 0) {
                $interestUnpaid = '0.00';
            }

            $due = Carbon::parse((string) $row->due_date)->toDateString();
            if ($due <= $today) {
                $accrued = bcadd($accrued, $interestUnpaid, 2);
            } else {
                $unaccrued = bcadd($unaccrued, $interestUnpaid, 2);
            }
        }

        return [
            'outstanding_capital' => $capital,
            'accrued_unpaid_interest' => $accrued,
            'unaccrued_forgiven_interest' => $unaccrued,
            'amount_to_close' => bcadd($capital, $accrued, 2),
            'deferred_interest_balance' => $this->money($contract->deferred_interest_balance ?? '0'),
        ];
    }

    /**
     * @return Collection<int, AmortizationInstallment>
     */
    private function unpaidInstallments(Contract $contract): Collection
    {
        return $contract->amortizationInstallments()
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->orderBy('installment_number')
            ->get();
    }

    private function statusOf(AmortizationInstallment $installment): string
    {
        return $installment->status instanceof AmortizationStatus
            ? $installment->status->value
            : (string) $installment->status;
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
    }
}
