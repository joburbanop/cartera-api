<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Http\Requests\UpdateUserRequest;

class UpdateUserDTO
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly ?string $role,
        public readonly ?string $password = null,
    ) {}

    public static function fromRequest(UpdateUserRequest $request): self
    {
        $password = $request->validated('password');

        return new self(
            name: $request->validated('name'),
            email: $request->validated('email'),
            role: $request->validated('role'),
            password: is_string($password) && $password !== '' ? $password : null,
        );
    }
}
