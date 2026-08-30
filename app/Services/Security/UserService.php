<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\DTOs\CreateUserDTO;
use App\DTOs\UpdateUserDTO;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class UserService
{
    /**
     * @return Collection<int, array{id: int, name: string, email: string, roles: list<string>}>
     */
    public function listUsers(): Collection
    {
        return User::query()
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => $this->presentUser($user));
    }

    /**
     * @return array{id: int, name: string, email: string, roles: list<string>}
     */
    public function createUser(CreateUserDTO $dto): array
    {
        $user = User::query()->create([
            'name' => $dto->name,
            'email' => $dto->email,
            'password' => $dto->password,
        ]);

        $user->syncRoles([RoleName::from($dto->role)->value]);

        return $this->presentUser($user->fresh(['roles']) ?? $user);
    }

    /**
     * @return array{id: int, name: string, email: string, roles: list<string>}
     */
    public function updateUser(User $user, UpdateUserDTO $dto): array
    {
        if ($dto->role !== null) {
            $this->guardLastAdminSistema($user, $dto->role);
        }

        $payload = array_filter([
            'name' => $dto->name,
            'email' => $dto->email,
        ], static fn (mixed $value): bool => $value !== null);

        if ($payload !== []) {
            $user->fill($payload)->save();
        }

        if ($dto->role !== null) {
            $user->syncRoles([RoleName::from($dto->role)->value]);
        }

        return $this->presentUser($user->fresh(['roles']) ?? $user);
    }

    /**
     * @return array{id: int, name: string, email: string, roles: list<string>}
     */
    public function assignRole(User $user, string $role): array
    {
        $this->guardLastAdminSistema($user, $role);
        $user->syncRoles([RoleName::from($role)->value]);

        return $this->presentUser($user->fresh(['roles']) ?? $user);
    }

    public function deleteUser(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages([
                'user' => ['No puedes eliminar tu propia cuenta.'],
            ]);
        }

        $this->guardLastAdminSistema($user, null);

        $user->tokens()->delete();
        $user->delete();
    }

    /**
     * @return array{id: int, name: string, email: string, roles: list<string>}
     */
    public function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
        ];
    }

    private function guardLastAdminSistema(User $user, ?string $newRole): void
    {
        if (! $user->hasRole(RoleName::ADMIN_SISTEMA->value)) {
            return;
        }

        $keepsRole = $newRole === RoleName::ADMIN_SISTEMA->value;
        if ($keepsRole) {
            return;
        }

        $remaining = User::role(RoleName::ADMIN_SISTEMA->value)
            ->whereKeyNot($user->id)
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'role' => ['Debe existir al menos un usuario con rol admin_sistema.'],
            ]);
        }
    }
}
