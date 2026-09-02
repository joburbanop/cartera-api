<?php

namespace App\Services\Financial\Refinancing;

use App\Enums\AmortizationStatus;
use App\Models\Contract;
use Carbon\Carbon;

class AcuerdoPagoService implements RefinanceStrategy
{
    public const DESCRIPTION = 'Abono fijo de refinanciación';

    public function apply(Contract $contract, array $params): void
    {
        $amount = bcadd((string) $params['extra_amount'], '0', 2);
        $months = (int) $params['months'];
        $startNumber = ((int) $contract->paymentPromises()->max('payment_number')) + 1;

        $startDate = $contract->amortizationInstallments()
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->where('installment_number', '>', 0)
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->value('due_date');

        $cursor = $startDate ? Carbon::parse((string) $startDate)->startOfDay() : now()->startOfDay();

        $payload = [];

        for ($index = 0; $index < $months; $index++) {
            $payload[] = [
                'payment_number' => $startNumber + $index,
                'expected_date' => $cursor->copy()->addMonthsNoOverflow($index)->toDateString(),
                'expected_amount' => $amount,
                'description' => self::DESCRIPTION,
                'is_paid' => false,
            ];
        }

        $contract->paymentPromises()->createMany($payload);
    }
}
