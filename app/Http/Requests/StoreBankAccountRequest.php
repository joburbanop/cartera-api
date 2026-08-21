<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Enums\BankAccountType; // <-- Importar el Enum

class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:100'],
            'account_number' => ['required', 'string', 'max:50', 'unique:bank_accounts,account_number'],
            // Usamos Rule::enum para validación estricta
            'account_type' => ['required', Rule::enum(BankAccountType::class)], 
            'holder_name' => ['required', 'string', 'max:150']
        ];
    }
}