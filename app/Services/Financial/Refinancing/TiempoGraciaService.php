<?php

namespace App\Services\Financial\Refinancing;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Support\DueDateRules;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class TiempoGraciaService implements RefinanceStrategy
{
    public function affectedInstallments(Contract $contract, array $params): Collection
    {
        return $contract->amortizationInstallments()
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->get();
    }

    public function apply(Contract $contract, array $params): void
    {
        $months = (int) $params['months'];

        $pending = $this->affectedInstallments($contract, $params);

        if ($pending->isEmpty()) {
            throw ValidationException::withMessages([
                'months' => 'No hay cuotas pendientes para aplicar tiempo de gracia.',
            ]);
        }

        foreach ($pending as $installment) {
            $newDueDate = Carbon::parse((string) $installment->due_date)
                ->startOfDay()
                ->addMonthsNoOverflow($months);

            $installment->update([
                'due_date' => $newDueDate->toDateString(),
                'status' => $this->statusAfterGrace($installment, $newDueDate)->value,
            ]);
        }
    }

    /**
     * Recalcula el estado con la fecha nueva. No toca el motor de amortización:
     * mora aquí es solo visual (due_date < hoy y saldo > 0).
     *
     * Una cuota "overdue" que queda con fecha futura debe pasar a pending
     * (o partial si ya tenía abono). Si sigue vencida, permanece overdue.
     */
    private function statusAfterGrace(AmortizationInstallment $installment, Carbon $newDueDate): AmortizationStatus
    {
        $stillOverdue = DueDateRules::isOverdue($newDueDate);

        if ($stillOverdue) {
            return AmortizationStatus::OVERDUE;
        }

        return $this->hasPartialPayment($installment)
            ? AmortizationStatus::PARTIAL
            : AmortizationStatus::PENDING;
    }

    private function hasPartialPayment(AmortizationInstallment $installment): bool
    {
        $status = $installment->status instanceof AmortizationStatus
            ? $installment->status->value
            : (string) $installment->status;

        if ($status === AmortizationStatus::PARTIAL->value) {
            return true;
        }

        $interestPaid = bcadd((string) ($installment->interest_paid ?? '0'), '0', 2);
        $principalPaid = bcadd((string) ($installment->principal_paid ?? '0'), '0', 2);
        $extraPayment = bcadd((string) ($installment->extra_payment ?? '0'), '0', 2);
        $paid = bcadd(bcadd($interestPaid, $principalPaid, 2), $extraPayment, 2);

        return bccomp($paid, '0.00', 2) > 0;
    }
}
