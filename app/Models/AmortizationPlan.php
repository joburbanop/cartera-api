<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\AmortizationStatus; // <-- Importamos nuestro Enum

class AmortizationPlan extends Model
{
    protected $fillable = [
        'contract_id',
        'version',
        'installment_number',
        'due_date',
        'installment_value',
        'principal_value',
        'interest_value',
        'remaining_balance',
        'interest_paid',
        'principal_paid',
        'quota_debt',
        'status',
        'is_active',
    ];

    // Casteos estrictos para seguridad matemática y de estados
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'installment_value' => 'decimal:2',
            'principal_value' => 'decimal:2',
            'interest_value' => 'decimal:2',
            'remaining_balance' => 'decimal:2',
            'interest_paid' => 'decimal:2',
            'principal_paid' => 'decimal:2',
            'quota_debt' => 'decimal:2',
            'status' => AmortizationStatus::class, // <-- Magia del Enum aquí
            'is_active' => 'boolean',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}