<?php

namespace App\Services;

use App\Enums\PaymentPromiseStatusEnum;
use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class PaymentPromiseStatusService
{
    public function decorate(Contract $contract, Collection $promises): Collection
    {
        $remainingPaid = $this->paidTotal($contract);
        $today = now()->startOfDay();

        $sorted = $promises
            ->sortBy([
                ['expected_date', 'asc'],
                ['payment_number', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        foreach ($sorted as $promise) {
            $expected = bcadd((string) ($promise->expected_amount ?? '0'), '0', 2);

            if (bccomp($remainingPaid, $expected, 2) >= 0) {
                $status = PaymentPromiseStatusEnum::PAGADA->value;
                $remainingAmount = '0.00';
                $remainingPaid = bcsub($remainingPaid, $expected, 2);
            } elseif (bccomp($remainingPaid, '0.00', 2) > 0) {
                $status = PaymentPromiseStatusEnum::PARCIAL->value;
                $remainingAmount = bcsub($expected, $remainingPaid, 2);
                $remainingPaid = '0.00';
            } else {
                $due = Carbon::parse((string) $promise->expected_date)->startOfDay();
                $status = $due->lt($today)
                    ? PaymentPromiseStatusEnum::VENCIDA->value
                    : PaymentPromiseStatusEnum::PENDIENTE->value;
                $remainingAmount = $expected;
            }

            $promise->setAttribute('status', $status);
            $promise->setAttribute('remaining_amount', $remainingAmount);
            $promise->setAttribute('is_paid', $status === PaymentPromiseStatusEnum::PAGADA->value);
        }

        return $sorted;
    }

    private function paidTotal(Contract $contract): string
    {
        $sum = $contract->transactions()->sum('amount');

        return bcadd((string) ($sum ?: '0'), '0', 2);
    }
}
