<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Enums\ContractStatus;


class Contract extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'contract_number',
        'customer_id',
        'lot_id',
        'seller_name',
        'sale_price',
        'down_payment_pactada',
        'term_months',
        'interest_rate',
        'start_date',
        'initial_payment_date',
        'first_installment_date',
        'regular_payment_start_date',
        'preventa_installments_count',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'initial_payment_date' => 'date',
            'first_installment_date' => 'date',
            'regular_payment_start_date' => 'date',
            'sale_price' => 'decimal:2',
            'down_payment_pactada' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'status' => ContractStatus::class, // Laravel validará y convertirá automáticamente este campo usando el Enum
        ];
    }

    // Relaciones
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function amortizationVersions(): HasMany
    {
        return $this->hasMany(AmortizationVersion::class);
    }

    public function activeAmortizationVersion(): HasOne
    {
        return $this->hasOne(AmortizationVersion::class)->where('is_active', true);
    }
}