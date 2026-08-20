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
            'project_id' => 'required|exists:projects,id',
            'number' => [
                'required', 'string', 'max:50',
                Rule::unique('lots')->where(fn ($query) => $query->where('project_id', $this->project_id))
            ],
            'area_m2' => 'required|numeric|min:1',
            'price_m2' => 'required|numeric|min:0',
            'list_price' => 'required|numeric|min:0',
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
}