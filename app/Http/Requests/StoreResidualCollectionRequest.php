<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Services\Residual\ResidualCollectionService;
use Illuminate\Foundation\Http\FormRequest;

class StoreResidualCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'transaction_date' => ['nullable', 'date'],
            'payment_date' => ['nullable', 'date'],
            'payment_method' => ['nullable', PaymentMethod::ruleForNewPayments()],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id', \Illuminate\Validation\Rule::requiredIf(fn () => strtolower((string) $this->input('payment_method', '')) === 'transfer')],
            'notes' => ['nullable', 'string', 'max:500'],
            'receipt_number' => ['required', 'string', 'max:80'],
            'receipt' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'receipt.required' => ResidualCollectionService::RECEIPT_REQUIRED,
            'amount.gt' => 'El monto debe ser mayor a cero.',
        ];
    }
}
