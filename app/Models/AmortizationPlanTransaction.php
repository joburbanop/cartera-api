<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class AmortizationPlanTransaction extends Model
{
    protected $table = 'amortization_plan_transaction';

    protected $fillable = [
        'amortization_plan_id',
        'transaction_id',
        'amount_applied',
    ];

    protected function casts(): array
    {
        return [
            'amount_applied' => 'decimal:2',
        ];
    }

    public function amortizationPlan(): BelongsTo
    {
        return $this->belongsTo(AmortizationPlan::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
