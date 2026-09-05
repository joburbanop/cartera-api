<?php

namespace App\Services\Financial\Refinancing;

use App\Enums\AmortizationStatus;
use App\Models\Contract;
use Illuminate\Validation\ValidationException;

class ExoneracionInteresesService implements RefinanceStrategy
{
    public function apply(Contract $contract, array $params): void
    {
        $ids = array_values(array_unique(array_map('intval', $params['installment_ids'] ?? [])));
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
            $status = $installment->status instanceof AmortizationStatus
                ? $installment->status->value
                : (string) $installment->status;

            if ($status === AmortizationStatus::PAID->value) {
                throw ValidationException::withMessages([
                    'installment_ids' => 'No se puede exonerar intereses de una cuota ya pagada.',
                ]);
            }
        }

        $factor = bcsub('1', bcdiv($percent, '100', 10), 10);

        foreach ($ids as $id) {
            $installment = $installments->get($id);
            $oldInterest = bcadd((string) ($installment->interest_value ?? '0'), '0', 2);
            $newInterest = bcmul($oldInterest, $factor, 2);
            $principal = bcadd((string) ($installment->principal_value ?? '0'), '0', 2);
            $newInstallmentValue = bcadd($principal, $newInterest, 2);
            $interestPaid = bcadd((string) ($installment->interest_paid ?? '0'), '0', 2);
            $principalPaid = bcadd((string) ($installment->principal_paid ?? '0'), '0', 2);

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

            $updates = [
                'interest_value' => $newInterest,
                'installment_value' => $newInstallmentValue,
            ];

            $status = $installment->status instanceof AmortizationStatus
                ? $installment->status->value
                : (string) $installment->status;

            $hasPartialPayment = $status === AmortizationStatus::PARTIAL->value
                || bccomp($interestPaid, '0.00', 2) > 0
                || bccomp($principalPaid, '0.00', 2) > 0;

            if ($hasPartialPayment) {
                $paidTotal = bcadd($interestPaid, $principalPaid, 2);
                $newDebt = bcsub($newInstallmentValue, $paidTotal, 2);
                $updates['quota_debt'] = bccomp($newDebt, '0.00', 2) < 0 ? '0.00' : $newDebt;
            }

            $installment->update($updates);
        }
    }
}
