<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un pago que el cliente hizo de una sola vez y que se reparte entre la cuota
 * inicial y las cuotas regulares. `amount` es lo que vio el banco y debe ser
 * igual a la suma de las dos partes.
 */
class StoreSplitPaymentRequest extends FormRequest
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
            'to_down_payment' => ['required', 'numeric', 'gt:0'],
            'to_installments' => ['required', 'numeric', 'gt:0'],
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
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $amount = $this->money((string) $this->input('amount'));
            $parts = bcadd(
                $this->money((string) $this->input('to_down_payment')),
                $this->money((string) $this->input('to_installments')),
                2
            );

            // Sin esta comprobación el recibo diría una cifra y la conciliación
            // bancaria otra.
            if (bccomp($amount, $parts, 2) !== 0) {
                $validator->errors()->add(
                    'amount',
                    'El reparto no suma el total del pago: '
                    .'$'.number_format((float) $parts, 2, ',', '.')
                    .' repartidos contra $'.number_format((float) $amount, 2, ',', '.').' recibidos.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'to_down_payment.gt' => 'Indica cuánto del pago va a la cuota inicial.',
            'to_installments.gt' => 'Indica cuánto del pago va a la cuota regular.',
            'selected_installments.*.exists' => 'No puedes incluir la Cuota Inicial entre las cuotas regulares.',
            'payment_option.required' => \App\Services\Collection\CascadeCollectionService::SURPLUS_ACTION_REQUIRED,
            'payment_option.in' => \App\Services\Collection\CascadeCollectionService::SURPLUS_ACTION_REQUIRED,
        ];
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
