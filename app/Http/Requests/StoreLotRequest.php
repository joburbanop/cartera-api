<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Enums\LotStatus;
use App\Enums\LotType;

class StoreLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
           
            'number' => [
                'required', 'string', 'max:50',
                Rule::unique('lots')->where(fn ($query) => $query->where('project_id', $this->project_id))
            ],
            'area_m2' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'price_m2' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'list_price' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'status' => ['nullable', Rule::enum(LotStatus::class)],
            'type' => ['nullable', Rule::enum(LotType::class)],
            'folio_matricula' => 'nullable|string|max:100|unique:lots,folio_matricula',
            'ficha_catastral' => 'nullable|string|max:100',
            'boundaries_north' => 'nullable|string',
            'boundaries_south' => 'nullable|string',
            'boundaries_east' => 'nullable|string',
            'boundaries_west' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'area_m2.required' => 'El área del lote es obligatoria.',
            'area_m2.numeric' => 'El área del lote debe ser un número válido.',
            'area_m2.min' => 'El área del lote no puede ser menor a 0.',
            'area_m2.max' => 'El área del lote no puede ser mayor a 999 billones.',

            'price_m2.required' => 'El precio por m² es obligatorio.',
            'price_m2.numeric' => 'El precio por m² debe ser un número válido.',
            'price_m2.min' => 'El precio por m² no puede ser menor a 0.',
            'price_m2.max' => 'El precio por m² no puede ser mayor a 999 billones.',

            'list_price.required' => 'El precio de lista es obligatorio.',
            'list_price.numeric' => 'El precio de lista debe ser un número válido.',
            'list_price.min' => 'El precio de lista no puede ser menor a 0.',
            'list_price.max' => 'El precio de lista no puede ser mayor a 999 billones.',
        ];
    }
}