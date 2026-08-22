<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'customer_id' => 'required|exists:customers,id',
            'lot_id' => 'required|exists:lots,id|unique:contracts,lot_id',
            'seller_name' => 'nullable|string|max:150',
            'sale_price' => 'required|numeric|min:0',
            'down_payment_pactada' => 'required|numeric|min:0',
            'term_months' => 'required|integer|min:1',
            'interest_rate' => 'nullable|numeric|min:0',
            'start_date' => 'required|date',
            'initial_payment_date' => ['required', 'date', 'after_or_equal:start_date'],
            'regular_payment_start_date' => ['required', 'date', 'after_or_equal:initial_payment_date'],
            'preventa_installments_count' => ['required', 'integer', 'min:0'],
        ];
    }
}