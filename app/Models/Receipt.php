<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    protected $fillable = [
    'transaction_id',
    'file_path',
    'file_name',
    'file_type',
];
    public function transaction(): BelongsTo
{
    return $this->belongsTo(Transaction::class);
}
}
