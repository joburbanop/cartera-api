<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RoleName;
use App\Http\Requests\Concerns\NormalizesEmailInput;
use App\Rules\UniqueUserEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        $userId = $this->route('user')?->id;

        return [
            'name' => 'sometimes|required|string|max:150',
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:150',
                new UniqueUserEmail($userId !== null ? (int) $userId : null),
            ],
            'role' => ['sometimes', 'required', 'string', Rule::in(RoleName::values())],
            'password' => 'sometimes|nullable|string|min:8',
        ];
    }
}
