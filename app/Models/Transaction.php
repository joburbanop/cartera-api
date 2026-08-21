<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;

class Transaction extends Model
{
      protected $fillable = [
        'contract_id',
        'transaction_type',
        'amount',
        'transaction_date',
        'payment_method',
    ];
     protected function casts(): array
    {
    return [
        'transaction_type' => TransactionType::class,
        'payment_method' => PaymentMethod::class,
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
    ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
    public function receipt(): HasOne
    {
    return $this->hasOne(Receipt::class);
    }
   
}
