<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\DTOs\LoginDTO;
use App\Services\Auth\AuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuthService $authService
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $dto = LoginDTO::fromRequest($request);
        
        $authData = $this->authService->login($dto);

        return $this->successResponse([
            'user' => $authData['user'],
            'access_token' => $authData['token'],
            'token_type' => 'Bearer'
        ], 'Inicio de sesión exitoso.');
    }
}