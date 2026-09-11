<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PaymentReversalReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReversePaymentRequest extends FormRequest
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
            'reason' => ['required', 'string', Rule::in(PaymentReversalReason::values())],
            'notes' => [
                Rule::requiredIf(fn () => $this->input('reason') === PaymentReversalReason::OTRO->value),
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'El motivo de la reversa es obligatorio.',
            'reason.in' => 'El motivo de la reversa no es válido.',
            'notes.required' => 'Describe el motivo cuando eliges Otro.',
        ];
    }
}
