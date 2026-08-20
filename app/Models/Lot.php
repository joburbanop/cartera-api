<?php

namespace App\Models;

use App\Enums\LotStatus;
use App\Enums\LotType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lot extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'number',
        'area_m2',
        'price_m2',
        'list_price',
        'status',
        'type',
        'folio_matricula',
        'ficha_catastral',
        'boundaries_north',
        'boundaries_south',
        'boundaries_east',
        'boundaries_west',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'area_m2' => 'decimal:2',
            'price_m2' => 'decimal:2',
            'list_price' => 'decimal:2',
            'status' => LotStatus::class,
            'type' => LotType::class,
        ];
    }

    // --- Relaciones ---

    // El lote pertenece a un proyecto
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // Auditoría
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
}