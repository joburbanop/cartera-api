<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Enums\BankAccountType;

class BankAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_name',
        'account_number',
        'account_type',
        'holder_name',
    ];

    // Magia de Laravel: Casteo automático
    protected $casts = [
        'account_type' => BankAccountType::class,
    ];
}