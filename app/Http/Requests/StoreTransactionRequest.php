<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $contractId = (int) $this->route('contractId');

        return [
            'amount' => 'required|numeric|gt:0',
            'transaction_date' => 'nullable|date',
            'payment_date' => 'nullable|date',
            'payment_method' => ['required', PaymentMethod::ruleForNewPayments()],
            'transaction_type' => 'sometimes|in:down_payment,regular_payment,extraordinary_payment,refund',
            'payment_option' => 'nullable|in:reducir_plazo,reducir_cuota,adelantar_cuotas,abono_capital,reduce_time,reduce_quota,transfer',
            'surplus_action' => 'nullable|in:reducir_plazo,reducir_cuota,adelantar_cuotas,abono_capital,reduce_time,reduce_quota,transfer',
            'recalculation_type' => 'nullable|in:reducir_plazo,reducir_cuota,adelantar_cuotas,abono_capital,reduce_time,reduce_quota,transfer',
            'installment_numbers' => 'nullable|array',
            'installment_numbers.*' => [
                'integer',
                'min:0',
                Rule::exists('amortization_installments', 'id')
                    ->where(fn ($query) => $query->where('contract_id', $contractId)),
            ],
            'selected_installments' => 'required|array',
            'selected_installments.*' => [
                'integer',
                Rule::exists('amortization_installments', 'id')
                    ->where(fn ($query) => $query->where('contract_id', $contractId)),
            ],
            'receipt' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'receipt_number' => 'nullable|string|max:80',
        ];
    }

    public function messages(): array
    {
        return [
            'selected_installments.*.exists' => 'La cuota no pertenece a este contrato.',
            'installment_numbers.*.exists' => 'La cuota no pertenece a este contrato.',
        ];
    }
}
