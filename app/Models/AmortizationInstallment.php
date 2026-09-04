<?php

namespace App\Models;

use App\Enums\AmortizationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmortizationInstallment extends Model
{
    protected $fillable = [
        'contract_id',
        'installment_number',
        'due_date',
        'receipt_number',
        'payment_date',
        'installment_value',
        'extra_payment',
        'interest_value',
        'principal_value',
        'interest_paid',
        'principal_paid',
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
        'interest_paid' => 'decimal:2',
        'principal_paid' => 'decimal:2',
        'quota_debt' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
        'projected_balance' => 'decimal:2',
        'status' => AmortizationStatus::class,
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
} 
