<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_name' => 'required|string|max:100',
            'account_number' => 'required|string|max:50|unique:bank_accounts,account_number',
            'account_type' => 'required|string|in:savings,checking', // Ahorros o Corriente
            'holder_name' => 'required|string|max:150'
        ];
    }
}