<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BankAccount extends Model
{
    // Los campos que se pueden llenar masivamente
    protected $fillable = [
        'bank_name',
        'account_number',
        'account_type',
        'is_active',
        'holder_name',
    ];

    // Forzar tipos de datos
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // Relación MUCHOS A MUCHOS: Una cuenta pertenece a muchos proyectos
    public function projects(): BelongsToMany
    {
        // Pasamos el modelo y el nombre exacto de la tabla pivote
        return $this->belongsToMany(Project::class, 'bank_account_project');
    }
}