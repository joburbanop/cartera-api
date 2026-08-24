<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AmortizationVersion extends Model
{
    protected $fillable = [
        'contract_id',
        'transaction_id',
        'version_number',
        'is_active',
        'recalculation_type',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(AmortizationInstallment::class)->orderBy('installment_number');
    }
}
