<?php

namespace App\Http\Requests;

use App\Enums\LotStatus;
use App\Enums\LotType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $lot = $this->route('lot');

        return [
            'number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('lots')
                    ->where(
                        fn ($query) => $query->where(
                            'project_id',
                            $lot->project_id
                        )
                    )
                    ->ignore($lot->id),
            ],

            'area_m2' => [
                'required',
                'numeric',
                'min:0',
                'max:999999999999',
            ],

            'list_price' => [
                'required',
                'numeric',
                'min:0',
                'max:999999999999',
            ],

            'status' => [
                'required',
                Rule::enum(LotStatus::class),
            ],

            'type' => [
                'required',
                Rule::enum(LotType::class),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'number.required' => 'El identificador del lote es obligatorio.',
            'number.string' => 'El identificador del lote debe ser texto.',
            'number.max' => 'El identificador del lote no puede superar los 50 caracteres.',
            'number.unique' => 'Ya existe un lote con este identificador en el proyecto.',

            'area_m2.required' => 'El área del lote es obligatoria.',
            'area_m2.numeric' => 'El área del lote debe ser un número válido.',
            'area_m2.min' => 'El área del lote no puede ser menor a 0.',
            'area_m2.max' => 'El área del lote no puede ser mayor a 999 billones.',

            'list_price.required' => 'El precio de lista es obligatorio.',
            'list_price.numeric' => 'El precio de lista debe ser un número válido.',
            'list_price.min' => 'El precio de lista no puede ser menor a 0.',
            'list_price.max' => 'El precio de lista no puede ser mayor a 999 billones.',

            'status.required' => 'El estado del lote es obligatorio.',
            'status.enum' => 'El estado seleccionado no es válido.',

            'type.required' => 'El tipo de lote es obligatorio.',
            'type.enum' => 'El tipo de lote seleccionado no es válido.',
        ];
    }
}