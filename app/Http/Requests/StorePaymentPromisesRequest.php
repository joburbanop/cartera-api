<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentPromisesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'promises' => ['required', 'array', 'min:1'],
            'promises.*.payment_number' => ['required', 'integer', 'min:1'],
            'promises.*.expected_date' => ['required', 'date'],
            'promises.*.expected_amount' => ['required', 'numeric', 'min:0'],
            'promises.*.description' => ['nullable', 'string', 'max:255'],
            'promises.*.is_paid' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'promises.required' => 'Debe enviar al menos una promesa de pago.',
            'promises.array' => 'El campo promises debe ser un arreglo.',
            'promises.*.expected_date.required' => 'La fecha esperada es obligatoria.',
            'promises.*.expected_amount.required' => 'El monto esperado es obligatorio.',
            'promises.*.expected_amount.numeric' => 'El monto esperado debe ser numérico.',
        ];
    }
}
