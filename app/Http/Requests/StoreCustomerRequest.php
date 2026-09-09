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
            // Correo, dirección y ciudad son obligatorios al dar de alta: sin
            // ellos no hay forma de notificar ni de visitar al cliente. En la
            // edición siguen siendo opcionales, para no bloquear las fichas
            // históricas que se crearon sin esos datos.
            'email' => 'required|email|max:150|unique:customers,email',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:100',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'El correo es obligatorio para poder notificar al cliente.',
            'address.required' => 'La dirección es obligatoria.',
            'city.required' => 'La ciudad es obligatoria.',
        ];
    }
}