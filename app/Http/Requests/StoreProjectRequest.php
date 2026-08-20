<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Más adelante lo conectaremos con los permisos (Spatie)
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150|unique:projects,name',
            'description' => 'nullable|string',
            'location' => 'required|string|max:255',
            'bank_account_ids' => 'required|array|min:1', // Debe enviar al menos una cuenta
            'bank_account_ids.*' => 'exists:bank_accounts,id', // Cada ID debe existir en la BD
        ];
    }
}