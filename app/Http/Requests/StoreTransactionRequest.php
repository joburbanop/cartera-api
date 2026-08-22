<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|gt:0',
            'transaction_date' => 'required|date',
            'payment_method' => 'required|in:cash,bank,barter,transfer,card',
            'transaction_type' => 'sometimes|in:down_payment,regular_payment,extraordinary_payment,refund',
            'payment_option' => 'nullable|in:reducir_plazo,reducir_cuota,adelantar_cuotas,reduce_time,reduce_quota,transfer',
            'surplus_action' => 'nullable|in:reducir_plazo,reducir_cuota,adelantar_cuotas,reduce_time,reduce_quota,transfer',
            'installment_numbers' => 'nullable|array',
            'installment_numbers.*' => 'integer|min:0',
            'receipt' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ];
    }
}