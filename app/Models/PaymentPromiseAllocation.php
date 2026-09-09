<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentPromiseAllocation extends Model
{
    protected $fillable = [
        'transaction_id',
        'payment_promise_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function promise(): BelongsTo
    {
        return $this->belongsTo(ContractPaymentPromise::class, 'payment_promise_id');
    }
}
