<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RoleName;
use App\Http\Requests\Concerns\NormalizesEmailInput;
use App\Rules\UniqueUserEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    use NormalizesEmailInput;

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
            'name' => 'required|string|max:150',
            'email' => ['required', 'string', 'email', 'max:150', new UniqueUserEmail],
            'password' => 'required|string|min:8',
            'role' => ['required', 'string', Rule::in(RoleName::values())],
        ];
    }
}
