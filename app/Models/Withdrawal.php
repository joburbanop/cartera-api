<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\WithdrawalStatus;
use App\Enums\WithdrawalCause;
use App\Enums\WithdrawalType;

class Withdrawal extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'type',
        'request_date',
        'cause',
        'observations',
        'sale_price',
        'contributions',
        'standard_retention_percentage',
        'authorized_retention_percentage',
        'penalty_amount',
        'refund_balance',
        'modification_justification',
        'authorized_by',
        'authorized_at',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'sale_price' => 'decimal:2',
            'contributions' => 'decimal:2',
            'standard_retention_percentage' => 'decimal:2',
            'authorized_retention_percentage' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'refund_balance' => 'decimal:2',
            'authorized_at' => 'datetime',
            'status' => WithdrawalStatus::class,
            'type' => WithdrawalType::class,
            'cause' => WithdrawalCause::class,
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}