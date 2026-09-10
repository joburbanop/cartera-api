<?php

namespace App\Observers;

use App\Models\Transaction;
use Illuminate\Support\Facades\Schema;

class TransactionObserver
{
    public function created(Transaction $transaction): void
    {
        if (! Schema::hasTable('activity_log')) {
            return;
        }

        $contract = $transaction->contract;
        if (! $contract) {
            return;
        }

        $properties = [
            'amount' => (string) $transaction->amount,
            'transaction_type' => $transaction->transaction_type?->value ?? (string) $transaction->transaction_type,
            'payment_method' => $transaction->payment_method?->value ?? (string) $transaction->payment_method,
            'transaction_id' => $transaction->id,
        ];

        if (filled($transaction->payment_option)) {
            $properties['payment_option'] = (string) $transaction->payment_option;
        }

        $receiptNumber = trim((string) ($transaction->receipt_number ?? ''));
        if ($receiptNumber !== '') {
            $properties['receipt_number'] = $receiptNumber;
        }

        $activity = activity()
            ->performedOn($contract)
            ->withProperties($properties);

        if (auth()->user()) {
            $activity->causedBy(auth()->user());
        }

        $phrase = sprintf(
            'Registró un pago de $%s mediante %s sobre el contrato',
            number_format((float) $transaction->amount, 2, '.', ','),
            $transaction->payment_method?->value ?? (string) $transaction->payment_method,
        );
        if ($receiptNumber !== '') {
            $phrase .= sprintf(' (Recibo #%s)', $receiptNumber);
        }

        $activity->log($phrase);
    }
}
