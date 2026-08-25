<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCascadePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'amount' => ['required', 'numeric', 'min:1'],
        ];
    }
}
