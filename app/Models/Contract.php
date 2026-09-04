<?php

namespace App\Models;

use App\Enums\ContractCustomerRole;
use App\Enums\ContractStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Contract extends Model
{
    use HasFactory, SoftDeletes;
    use LogsActivity {
        shouldLogEvent as protected spatieShouldLogEvent;
    }

    protected static function booted(): void
    {
        static::created(function (Contract $contract) {
            if ($contract->customer_id) {
                $contract->syncHolders((int) $contract->customer_id);
            }
        });
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
        'is_custom_plan',
        'is_special_lot',
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
            'preventa_installments_count' => 'integer',
            'is_custom_plan' => 'boolean',
            'is_special_lot' => 'boolean',
            'status' => ContractStatus::class, // Laravel validará y convertirá automáticamente este campo usando el Enum
        ];
    }

    // Relaciones
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'contract_customer')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function primaryCustomer(): ?Customer
    {
        if ($this->relationLoaded('customers')) {
            $fromPivot = $this->customers->first(
                fn (Customer $customer) => $customer->pivot?->role === ContractCustomerRole::TITULAR_PRINCIPAL->value
            );

            if ($fromPivot) {
                return $fromPivot;
            }
        }

        if (Schema::hasTable('contract_customer')) {
            $fromPivot = $this->customers()
                ->wherePivot('role', ContractCustomerRole::TITULAR_PRINCIPAL->value)
                ->first();

            if ($fromPivot) {
                return $fromPivot;
            }
        }

        return $this->customer;
    }

    public function holderDisplayName(): string
    {
        if ($this->relationLoaded('customers') && $this->customers->isNotEmpty()) {
            $names = $this->customers
                ->sortBy(fn (Customer $customer) => $customer->pivot?->role === ContractCustomerRole::TITULAR_PRINCIPAL->value ? 0 : 1)
                ->pluck('name')
                ->filter()
                ->values();

            if ($names->isNotEmpty()) {
                return $names->implode(', ');
            }
        }

        return $this->primaryCustomer()?->name ?? 'Sin Cliente';
    }

    /**
     * @param  list<int|string>  $coTitularIds
     */
    public function syncHolders(int $primaryCustomerId, array $coTitularIds = []): void
    {
        if (! Schema::hasTable('contract_customer') || $primaryCustomerId <= 0) {
            return;
        }

        $sync = [
            $primaryCustomerId => ['role' => ContractCustomerRole::TITULAR_PRINCIPAL->value],
        ];

        foreach (array_unique($coTitularIds) as $id) {
            $id = (int) $id;
            if ($id <= 0 || $id === $primaryCustomerId) {
                continue;
            }

            $sync[$id] = ['role' => ContractCustomerRole::CO_TITULAR->value];
        }

        $this->customers()->sync($sync);
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
                'is_custom_plan',
                'is_special_lot',
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
