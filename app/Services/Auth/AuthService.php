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
     * @return array{user: array{id: int, name: string, email: string, roles: list<string>}, roles: list<string>, token: string}
     */
    public function login(LoginDTO $dto): array
    {
        $user = User::where('email', $dto->email)->first();

        if (! $user || ! Hash::check($dto->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        $presented = $this->userService->presentUser($user);
        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user' => $presented,
            'roles' => $presented['roles'],
            'token' => $token,
        ];
    }
}