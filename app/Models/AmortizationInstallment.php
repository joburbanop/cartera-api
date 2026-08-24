<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmortizationInstallment extends Model
{
    protected $fillable = [
        'amortization_version_id',
        'installment_number',
        'due_date',
        'receipt_number',
        'payment_date',
        'installment_value',
        'extra_payment',
        'interest_value',
        'principal_value',
        'quota_debt',
        'remaining_balance',
        'projected_balance',
        'status',
    ];

    protected $casts = [
        'due_date' => 'date',
        'payment_date' => 'datetime',
        'installment_value' => 'decimal:2',
        'extra_payment' => 'decimal:2',
        'interest_value' => 'decimal:2',
        'principal_value' => 'decimal:2',
        'quota_debt' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
        'projected_balance' => 'decimal:2',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(AmortizationVersion::class, 'amortization_version_id');
    }
}
