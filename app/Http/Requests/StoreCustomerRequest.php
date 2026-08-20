<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Enums\DocumentType;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::enum(DocumentType::class)],
            'document_number' => 'required|string|max:50|unique:customers,document_number',
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:50', // Obligatorio para cobranza
            'email' => 'nullable|email|max:150|unique:customers,email',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
        ];
    }
}