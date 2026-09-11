<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    protected $fillable = [
        'contract_id',
        'transaction_type',
        'amount',
        'transaction_date',
        'payment_method',
        'bank_account_id',
        'notes',
        'receipt_number',
        'payment_option',
        'reversed_at',
        'reversed_by',
        'reversal_transaction_id',
        'reversal_reason',
        'reversal_notes',
    ];

    protected function casts(): array
    {
        return [
            'transaction_type' => TransactionType::class,
            'payment_method' => PaymentMethod::class,
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    /**
     * Reparto interno del pago hacia amortización (inicial, cuota, capital).
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(TransactionAllocation::class);
    }

    public function promiseAllocations(): HasMany
    {
        return $this->hasMany(PaymentPromiseAllocation::class);
    }

    public function reversedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function reversalTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_transaction_id');
    }

    public function reversedOriginals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_transaction_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function isReversal(): bool
    {
        return $this->transaction_type === TransactionType::PAYMENT_REVERSAL;
    }

    public function scopeNotReversed($query)
    {
        return $query->whereNull('reversed_at');
    }

    public function scopeCollections($query)
    {
        return $query->where('transaction_type', '!=', TransactionType::PAYMENT_REVERSAL);
    }
}
