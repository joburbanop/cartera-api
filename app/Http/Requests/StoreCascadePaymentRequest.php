<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Enums\PaymentMethod;

class StoreCascadePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $contractId = (int) $this->input('contract_id');

        return [
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'payment_option' => ['nullable', 'string', 'in:reducir_plazo,reducir_cuota,adelantar_cuotas'],
            'transaction_date' => ['nullable', 'date'],
            'payment_date' => ['nullable', 'date'],
            'selected_installments' => ['nullable', 'array'],
            'selected_installments.*' => [
                'integer',
                Rule::exists('amortization_installments', 'id')
                    ->where(fn ($query) => $query
                        ->where('contract_id', $contractId)
                        ->where('installment_number', '>', 0)
                    ),
            ],
            'installment_numbers' => ['nullable', 'array'],
            'installment_numbers.*' => [
                'integer',
                Rule::exists('amortization_installments', 'id')
                    ->where(fn ($query) => $query
                        ->where('contract_id', $contractId)
                        ->where('installment_number', '>', 0)
                    ),
            ],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'selected_installments.*.exists' => 'No puedes incluir la Cuota Inicial en un pago de Cuotas Ordinarias.',
            'installment_numbers.*.exists' => 'No puedes incluir la Cuota Inicial en un pago de Cuotas Ordinarias.',
        ];
    }
}
