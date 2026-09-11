<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'holder_name' => [
                'required',
                'string',
                'max:150',
            ],

            'is_active' => [
                'required',
                'boolean',
            ],
        ];
    }
}