<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\DTOs\CreateUserDTO;
use App\DTOs\UpdateUserDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignUserRoleRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\Security\UserService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected UserService $userService
    ) {}

    public function index(): JsonResponse
    {
        return $this->successResponse(
            $this->userService->listUsers(),
            'Lista de usuarios obtenida exitosamente.'
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->createUser(CreateUserDTO::fromRequest($request));

        return $this->successResponse($user, 'Usuario creado exitosamente.', 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $updated = $this->userService->updateUser($user, UpdateUserDTO::fromRequest($request));

        return $this->successResponse($updated, 'Usuario actualizado exitosamente.');
    }

    public function assignRole(AssignUserRoleRequest $request, User $user): JsonResponse
    {
        $updated = $this->userService->assignRole($user, $request->validated('role'));

        return $this->successResponse($updated, 'Rol asignado exitosamente.');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->userService->deleteUser($user, $request->user());

        return $this->successResponse(null, 'Usuario eliminado exitosamente.');
    }
}
