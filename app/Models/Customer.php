<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Customer extends Model
{
    use HasFactory, SoftDeletes;
    use LogsActivity {
        shouldLogEvent as protected spatieShouldLogEvent;
    }

    protected $fillable = [
        'document_type',
        'document_number',
        'name',
        'phone',
        'email',
        'address',
        'city',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $hidden = [
        'pivot',
    ];

    protected function casts(): array
    {
        return [
            // Laravel validará y convertirá automáticamente este campo usando el Enum
            'document_type' => DocumentType::class,
        ];
    }

    // --- Relaciones de Auditoría ---
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    // --- Relaciones de Negocio ---
    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class, 'contract_customer')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function activeContracts(): BelongsToMany
    {
        return $this->contracts()
            ->whereIn('contracts.status', [
                ContractStatus::ACTIVO->value,
                ContractStatus::PREVENTA_INACTIVA->value,
                'active',
                'preventa',
            ]);
    }

    public function activeContract(): HasOne
    {
        return $this->hasOne(Contract::class)
            ->whereIn('status', [
                ContractStatus::ACTIVO->value,
                ContractStatus::PREVENTA_INACTIVA->value,
                'active',
                'preventa',
            ])
            ->latest();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('cliente')
            ->logOnly([
                'document_type',
                'document_number',
                'name',
                'phone',
                'email',
                'address',
                'city',
            ])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => 'Creó cliente',
                'updated' => 'Actualizó cliente',
                'deleted' => 'Eliminó cliente',
                default => $eventName,
            });
    }

    protected function shouldLogEvent(string $eventName): bool
    {
        return Schema::hasTable('activity_log') && $this->spatieShouldLogEvent($eventName);
    }
}
