<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\LoginDTO;
use App\Models\User;
use App\Services\Security\UserService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        protected UserService $userService
    ) {}

    /**
     * @return array{user: array{id: int, name: string, email: string, roles: list<string>, permissions: list<string>}, roles: list<string>, token: string}
     */
    public function login(LoginDTO $dto): array
    {
        $user = User::findByEmail($dto->email);

        if (! $user || ! Hash::check($dto->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['El correo o la contraseña no son correctos.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $presented = $this->userService->presentUser($user);
        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user' => $presented,
            'roles' => $presented['roles'],
            'token' => $token,
        ];
    }
}