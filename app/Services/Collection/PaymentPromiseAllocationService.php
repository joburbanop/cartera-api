<?php

namespace App\Services\Collection;

use App\Models\Contract;
use App\Models\ContractPaymentPromise;
use App\Models\PaymentPromiseAllocation;
use App\Models\Transaction;
use App\Services\PaymentPromiseStatusService;
use Illuminate\Support\Collection;

/**
 * Anota, en el momento del cobro, contra qué cuota pactada fue el dinero
 * regular (no la parte a inicial). No reconstruye historia: solo el pago actual.
 */
class PaymentPromiseAllocationService
{
    public function __construct(
        private readonly PaymentPromiseStatusService $promiseStatusService,
    ) {}

    public function allocate(Contract $contract, Transaction $transaction, string $amount): void
    {
        $amount = $this->money($amount);
        if (bccomp($amount, '0.00', 2) <= 0) {
            return;
        }

        $promises = $contract->paymentPromises()
            ->orderBy('expected_date')
            ->orderBy('payment_number')
            ->orderBy('id')
            ->get();

        if ($promises->isEmpty()) {
            return;
        }

        $after = $this->promiseStatusService->regularCollected($contract);
        $before = $this->money(bcsub($after, $amount, 2));
        if (bccomp($before, '0.00', 2) < 0) {
            $before = '0.00';
        }

        $remainings = $this->fifoRemainings($promises, $before);

        foreach ($remainings as $row) {
            if (bccomp($amount, '0.00', 2) <= 0) {
                break;
            }

            $open = $row['remaining'];
            if (bccomp($open, '0.00', 2) <= 0) {
                continue;
            }

            $applied = bccomp($amount, $open, 2) <= 0 ? $amount : $open;
            PaymentPromiseAllocation::query()->create([
                'transaction_id' => $transaction->id,
                'payment_promise_id' => $row['id'],
                'amount' => $applied,
            ]);
            $amount = $this->money(bcsub($amount, $applied, 2));
        }
    }

    /**
     * @return list<array{id: int, remaining: string}>
     */
    private function fifoRemainings(Collection $promises, string $paidTotal): array
    {
        $remainingPaid = $this->money($paidTotal);
        $rows = [];

        foreach ($promises as $promise) {
            if (! $promise instanceof ContractPaymentPromise) {
                continue;
            }

            $expected = $this->money((string) ($promise->expected_amount ?? '0'));

            if (bccomp($remainingPaid, $expected, 2) >= 0) {
                $rows[] = ['id' => (int) $promise->id, 'remaining' => '0.00'];
                $remainingPaid = $this->money(bcsub($remainingPaid, $expected, 2));
                continue;
            }

            if (bccomp($remainingPaid, '0.00', 2) > 0) {
                $rows[] = [
                    'id' => (int) $promise->id,
                    'remaining' => $this->money(bcsub($expected, $remainingPaid, 2)),
                ];
                $remainingPaid = '0.00';
                continue;
            }

            $rows[] = ['id' => (int) $promise->id, 'remaining' => $expected];
        }

        return $rows;
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
