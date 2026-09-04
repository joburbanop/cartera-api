<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Enums\DocumentType;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $customerId = $this->route('customer');

        return [
            'document_type' => [
                'sometimes',
                Rule::enum(DocumentType::class),
            ],

            'document_number' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('customers', 'document_number')
                    ->ignore($customerId),
            ],

            'name' => [
                'sometimes',
                'string',
                'max:150',
            ],

            'phone' => [
                'sometimes',
                'string',
                'max:50',
            ],

            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:150',
                Rule::unique('customers', 'email')
                    ->ignore($customerId),
            ],

            'address' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'city' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],
        ];
    }
}