<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Enums\BankAccountType;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BankAccount extends Model
{
    use HasFactory, SoftDeletes, LogsActivity {
        shouldLogEvent as protected spatieShouldLogEvent;
    }

    protected $fillable = [
        'bank_name',
        'account_number',
        'account_type',
        'holder_name',
        'is_active',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'account_type' => BankAccountType::class,
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('cuenta_bancaria')
            ->logOnly([
                'bank_name',
                'account_number',
                'account_type',
                'holder_name',
                'is_active',
            ])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => 'Creó cuenta bancaria',
                'updated' => 'Actualizó cuenta bancaria',
                'deleted' => 'Archivó cuenta bancaria',
                'restored' => 'Restauró cuenta bancaria',
                default => $eventName,
            });
    }

    protected function shouldLogEvent(string $eventName): bool
    {
        return Schema::hasTable('activity_log')
            && $this->spatieShouldLogEvent($eventName);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'bank_account_project');
    }

}