<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOwnPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contractTabs' => ['required', 'array', 'min:1'],
            'contractTabs.*' => ['string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contractTabs.required' => 'El orden de pestañas es obligatorio.',
            'contractTabs.array' => 'El orden de pestañas no es válido.',
        ];
    }
}
