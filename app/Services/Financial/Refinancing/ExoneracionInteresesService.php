<?php

namespace App\Services\Financial\Refinancing;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ExoneracionInteresesService implements RefinanceStrategy
{
    public function affectedInstallments(Contract $contract, array $params): Collection
    {
        $selected = $contract->amortizationInstallments()
            ->whereIn('id', $this->requestedIds($params))
            ->get();

        $unpaid = $contract->amortizationInstallments()
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->get();

        return $selected->merge($unpaid)
            ->unique('id')
            ->sortBy('installment_number')
            ->values();
    }

    public function apply(Contract $contract, array $params): void
    {
        $ids = $this->requestedIds($params);
        $percent = bcadd((string) $params['reduction_percent'], '0', 2);

        $installments = $contract->amortizationInstallments()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($installments->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'installment_ids' => 'Todas las cuotas deben pertenecer al contrato y existir.',
            ]);
        }

        foreach ($ids as $id) {
            $installment = $installments->get($id);
            if ($this->statusOf($installment) === AmortizationStatus::PAID->value) {
                throw ValidationException::withMessages([
                    'installment_ids' => 'No se puede exonerar intereses de una cuota ya pagada.',
                ]);
            }
        }

        $factor = bcsub('1', bcdiv($percent, '100', 10), 10);

        foreach ($ids as $id) {
            $installment = $installments->get($id);
            $oldInterest = $this->money($installment->interest_value ?? '0');
            $newInterest = bcmul($oldInterest, $factor, 2);
            $principal = $this->money($installment->principal_value ?? '0');
            $newInstallmentValue = bcadd($principal, $newInterest, 2);
            $interestPaid = $this->money($installment->interest_paid ?? '0');
            $principalPaid = $this->money($installment->principal_paid ?? '0');

            if (bccomp($interestPaid, $newInterest, 2) > 0) {
                throw ValidationException::withMessages([
                    'installment_ids' => sprintf(
                        'La cuota %d ya tiene intereses pagados (%s) mayores al nuevo interés reducido (%s). No se aplicó la exoneración.',
                        (int) $installment->installment_number,
                        $interestPaid,
                        $newInterest,
                    ),
                ]);
            }

            $paidTotal = bcadd($interestPaid, $principalPaid, 2);
            $newDebt = bcsub($newInstallmentValue, $paidTotal, 2);

            $installment->update([
                'interest_value' => $newInterest,
                'installment_value' => $newInstallmentValue,
                'quota_debt' => bccomp($newDebt, '0.00', 2) < 0 ? '0.00' : $newDebt,
            ]);
        }

        $this->recalculateUnpaidRemainingBalances($contract);
    }

    /**
     * Carrera de capital desde la última cuota pagada. No cambia principal_value
     * ni el interés de las cuotas que no se exoneraron.
     */
    private function recalculateUnpaidRemainingBalances(Contract $contract): void
    {
        $rows = $contract->amortizationInstallments()
            ->orderBy('installment_number')
            ->get();

        $running = bcsub(
            $this->money($contract->sale_price),
            $this->money($contract->down_payment_pactada),
            2
        );

        foreach ($rows as $row) {
            if ($this->statusOf($row) === AmortizationStatus::PAID->value) {
                $running = $this->money($row->remaining_balance ?? '0');

                continue;
            }

            $remaining = bcsub($running, $this->money($row->principal_value ?? '0'), 2);
            if (bccomp($remaining, '0.00', 2) < 0) {
                $remaining = '0.00';
            }

            $row->update([
                'remaining_balance' => $remaining,
                'projected_balance' => $remaining,
            ]);

            $running = $remaining;
        }
    }

    /**
     * @return list<int>
     */
    private function requestedIds(array $params): array
    {
        return array_values(array_unique(array_map('intval', $params['installment_ids'] ?? [])));
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
