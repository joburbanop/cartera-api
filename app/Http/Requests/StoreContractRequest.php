<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_number' => 'required|string|max:100|unique:contracts,contract_number',
            'customer_id' => 'nullable|integer',
            'customer_name' => 'nullable|string|max:150',
            'customer_document' => 'nullable|string|max:50',
            'customer_phone' => 'nullable|string|max:20',
            'customer_email' => 'nullable|email|max:150',
            'lot_id' => [
                'required',
                'exists:lots,id',
                Rule::unique('contracts', 'lot_id')
                    ->where(fn ($query) => $query
                        ->whereNull('deleted_at')
                        ->whereNotIn('status', ['rescindido']))
            ],
            'seller_name' => 'nullable|string|max:150',
            'sale_price' => 'required|numeric|min:0',
            'down_payment_pactada' => 'required|numeric|min:0',
            'term_months' => 'required|integer|min:1',
            'interest_rate' => 'nullable|numeric|min:0',
            'start_date' => 'required|date',
            'initial_payment_date' => ['required', 'date', 'after_or_equal:start_date'],
            'first_installment_date' => ['required', 'date', 'after_or_equal:start_date'],
            'regular_payment_start_date' => ['nullable', 'date', 'after_or_equal:first_installment_date'],
            'preventa_installments_count' => ['required', 'integer', 'min:0'],
        ];
    }
}