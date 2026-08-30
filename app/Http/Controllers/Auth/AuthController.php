<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\DTOs\LoginDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Services\Auth\AuthService;
use App\Services\Security\UserService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuthService $authService,
        protected UserService $userService
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $authData = $this->authService->login(LoginDTO::fromRequest($request));

        return $this->successResponse([
            'user' => $authData['user'],
            'roles' => $authData['roles'],
            'access_token' => $authData['token'],
            'token_type' => 'Bearer',
        ], 'Inicio de sesión exitoso.');
    }

    public function me(Request $request): JsonResponse
    {
        $presented = $this->userService->presentUser($request->user());

        return $this->successResponse([
            'user' => $presented,
            'roles' => $presented['roles'],
        ], 'Usuario autenticado.');
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return $this->successResponse(null, 'Sesión cerrada.');
    }
}