<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefinanceContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tipo = (string) $this->input('tipo');

        return [
            'tipo' => ['required', 'in:acuerdo_pago,tiempo_gracia,refinanciar_saldo,exoneracion_intereses'],
            'extra_amount' => [
                Rule::requiredIf($tipo === 'acuerdo_pago'),
                'numeric',
                'gt:0',
            ],
            'months' => [
                Rule::requiredIf(in_array($tipo, ['acuerdo_pago', 'tiempo_gracia'], true)),
                'integer',
                'min:1',
                'max:240',
            ],
            'new_term_months' => [
                Rule::requiredIf($tipo === 'refinanciar_saldo'),
                'integer',
                'min:1',
                'max:360',
            ],
            'new_interest_rate' => [
                Rule::requiredIf($tipo === 'refinanciar_saldo'),
                'numeric',
                'min:0',
            ],
            'installment_ids' => [
                Rule::requiredIf($tipo === 'exoneracion_intereses'),
                'array',
                'min:1',
            ],
            'installment_ids.*' => ['integer'],
            'reduction_percent' => [
                Rule::requiredIf($tipo === 'exoneracion_intereses'),
                'numeric',
                'min:0',
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo.required' => 'Debe indicar el tipo de refinanciación.',
            'tipo.in' => 'El tipo de refinanciación no es válido.',
            'extra_amount.required' => 'El abono fijo es obligatorio.',
            'extra_amount.gt' => 'El abono fijo debe ser mayor a cero.',
            'months.required' => 'La cantidad de meses es obligatoria.',
            'months.min' => 'La cantidad de meses debe ser al menos 1.',
            'new_term_months.required' => 'El nuevo plazo es obligatorio.',
            'new_term_months.min' => 'El nuevo plazo debe ser al menos 1 mes.',
            'new_interest_rate.required' => 'La nueva tasa es obligatoria.',
            'installment_ids.required' => 'Debe seleccionar al menos una cuota.',
            'reduction_percent.required' => 'El porcentaje de reducción es obligatorio.',
            'reduction_percent.max' => 'El porcentaje de reducción no puede superar 100.',
            'reduction_percent.min' => 'El porcentaje de reducción no puede ser negativo.',
        ];
    }
}
