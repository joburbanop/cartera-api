<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use App\Enums\ContractStatus;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;


class Contract extends Model
{
    use HasFactory, SoftDeletes;
    use LogsActivity {
        shouldLogEvent as protected spatieShouldLogEvent;
    }

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

    public function installments(): HasMany
    {
        return $this->hasMany(AmortizationInstallment::class)->orderBy('installment_number', 'asc');
    }

    public function amortizationInstallments(): HasMany
    {
        return $this->installments();
    }

    public function paymentPromises(): HasMany
    {
        return $this->hasMany(ContractPaymentPromise::class)->orderBy('payment_number', 'asc');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('contrato')
            ->logOnly([
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
            ])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => 'Creó contrato',
                'updated' => 'Actualizó contrato',
                'deleted' => 'Eliminó contrato',
                default => $eventName,
            });
    }

    protected function shouldLogEvent(string $eventName): bool
    {
        return Schema::hasTable('activity_log') && $this->spatieShouldLogEvent($eventName);
    }
}