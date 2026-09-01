<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Más adelante lo conectaremos con los permisos (Spatie)
    }

    public function rules(): array
    {
        $projectId = $this->route('project')->id;

        return [
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('projects', 'name')->ignore($projectId),
            ],
            'description' => 'nullable|string',
            'location' => 'required|string|max:255',
            'bank_account_ids' => 'required|array|min:1',
            'bank_account_ids.*' => 'exists:bank_accounts,id',
        ];
    }
}