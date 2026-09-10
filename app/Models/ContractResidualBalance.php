<?php

namespace App\Models;

use App\Enums\ResidualBalanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractResidualBalance extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'contract_id',
        'amortization_installment_id',
        'amount',
        'status',
        'collected_transaction_id',
        'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => ResidualBalanceStatus::class,
            'created_at' => 'datetime',
            'collected_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(AmortizationInstallment::class, 'amortization_installment_id');
    }

    public function collectedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'collected_transaction_id');
    }
}
