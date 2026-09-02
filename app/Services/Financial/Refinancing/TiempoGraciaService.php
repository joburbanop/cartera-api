<?php

namespace App\Services\Financial\Refinancing;

use App\Enums\AmortizationStatus;
use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class TiempoGraciaService implements RefinanceStrategy
{
    public function apply(Contract $contract, array $params): void
    {
        $months = (int) $params['months'];

        $pending = $contract->amortizationInstallments()
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->get();

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
            ]);
        }
    }
}
