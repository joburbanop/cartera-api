<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use SoftDeletes; // Activa el borrado lógico (no borra de la DB, solo oculta)

    protected $fillable = [
        'name',
        'description',
        'location',
        'status',
        'created_by',
        'updated_by',
    ];

    // Relación MUCHOS A MUCHOS: Un proyecto tiene muchas cuentas
    public function bankAccounts(): BelongsToMany
    {
        return $this->belongsToMany(BankAccount::class, 'bank_account_project');
    }

    // Relación: Usuario que creó el proyecto
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Relación: Usuario que actualizó el proyecto por última vez
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function lots()
    {
        return $this->hasMany(Lot::class);
    }
    public function statusHistory(): HasMany
    {
        return $this->hasMany(ProjectStatusHistory::class);
    }
}