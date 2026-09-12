<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Services\Financial\Transaction\TransactionService;
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
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id', Rule::requiredIf(fn () => strtolower((string) $this->input('payment_method', '')) === 'transfer')],
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
            'receipt_number' => 'required|string|max:80',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $rejected = [
                'regular_payment' => TransactionService::REGULAR_PAYMENT_USE_CASCADE,
                'extraordinary_payment' => TransactionService::EXTRAORDINARY_PAYMENT_USE_CASCADE,
                'refund' => TransactionService::REFUND_NOT_ACCEPTED,
            ];
            $type = $this->resolvedTransactionType();

            if (! isset($rejected[$type])) {
                return;
            }

            $validator->errors()->add('transaction_type', $rejected[$type]);
        });
    }

    public function messages(): array
    {
        return [
            'selected_installments.*.exists' => 'La cuota no pertenece a este contrato.',
            'installment_numbers.*.exists' => 'La cuota no pertenece a este contrato.',
        ];
    }

    private function resolvedTransactionType(): string
    {
        $explicit = (string) $this->input('transaction_type', $this->input('transactionType', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $selected = $this->input('selected_installments', $this->input('installment_numbers', []));

        return is_array($selected) && $selected !== []
            ? 'regular_payment'
            : 'down_payment';
    }
}
