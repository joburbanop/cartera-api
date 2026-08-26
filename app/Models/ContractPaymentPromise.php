<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractPaymentPromise extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'payment_number',
        'expected_date',
        'expected_amount',
        'description',
        'is_paid',
    ];

    protected function casts(): array
    {
        return [
            'expected_date' => 'date',
            'expected_amount' => 'decimal:2',
            'is_paid' => 'boolean',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
