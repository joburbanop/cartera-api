<?php

namespace App\Observers;

use App\Models\Transaction;

class TransactionObserver
{
    public function created(Transaction $transaction): void
    {
        $contract = $transaction->contract;
        if (! $contract) {
            return;
        }

        $activity = activity()
            ->performedOn($contract)
            ->withProperties([
                'amount' => (string) $transaction->amount,
                'transaction_type' => $transaction->transaction_type?->value ?? (string) $transaction->transaction_type,
                'payment_method' => $transaction->payment_method?->value ?? (string) $transaction->payment_method,
                'transaction_id' => $transaction->id,
            ]);

        if (auth()->user()) {
            $activity->causedBy(auth()->user());
        }

        $activity->log(sprintf(
            'Registró un pago de $%s mediante %s sobre el contrato',
            number_format((float) $transaction->amount, 2, '.', ','),
            $transaction->payment_method?->value ?? (string) $transaction->payment_method,
        ));
    }
}
