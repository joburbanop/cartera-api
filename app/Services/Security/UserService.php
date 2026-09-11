<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\DTOs\CreateUserDTO;
use App\DTOs\UpdateUserDTO;
use App\Enums\RoleName;
use App\Models\User;
use App\Support\ContractTabOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
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
            'must_change_password' => true,
        ]);

        $user->syncRoles([RoleName::from($dto->role)->value]);

        return $this->presentUser($user->fresh(['roles']) ?? $user);
    }

    /**
     * @return array{id: int, name: string, email: string, roles: list<string>}
     */
    public function updateUser(User $user, UpdateUserDTO $dto, User $actor): array
    {
        if ($dto->role !== null) {
            $this->guardLastAdminSistema($user, $dto->role);
        }

        $payload = array_filter([
            'name' => $dto->name,
            'email' => $dto->email,
        ], static fn (mixed $value): bool => $value !== null);

        if ($payload !== []) {
            $user->fill($payload);
        }

        if ($dto->password !== null) {
            $user->password = $dto->password;
            $changingOwnPassword = $user->is($actor);
            $user->must_change_password = ! $changingOwnPassword;
            if ($changingOwnPassword) {
                $user->password_changed_at = now();
            }
        }

        if ($payload !== [] || $dto->password !== null) {
            $user->save();
        }

        if ($dto->password !== null && ! $user->is($actor)) {
            $user->tokens()->delete();
        }

        if ($dto->role !== null) {
            $user->syncRoles([RoleName::from($dto->role)->value]);
        }

        return $this->presentUser($user->fresh(['roles']) ?? $user);
    }

    public function changeOwnPassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual no es correcta.'],
            ]);
        }

        if (Hash::check($newPassword, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['La nueva contraseña debe ser diferente a la actual.'],
            ]);
        }

        $user->password = $newPassword;
        $user->must_change_password = false;
        $user->password_changed_at = now();
        $user->save();

        $currentToken = $user->currentAccessToken();
        $currentTokenId = $currentToken && isset($currentToken->id) ? $currentToken->id : null;

        $query = $user->tokens();
        if ($currentTokenId) {
            $query->whereKeyNot($currentTokenId);
        }
        $query->delete();
    }

    public function clearMustChangePassword(User $user): void
    {
        $user->forceFill(['must_change_password' => false])->save();
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
     * @return array{id: int, name: string, email: string, roles: list<string>, permissions: list<string>, must_change_password: bool, password_changed_at: string|null, ui_preferences: array{contractTabs: list<string>}}
     */
    public function presentUser(User $user): array
    {
        $user->loadMissing(['roles', 'permissions']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->unique()->values()->all(),
            'must_change_password' => (bool) $user->must_change_password,
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            'ui_preferences' => $this->presentUiPreferences($user),
        ];
    }

    /**
     * @param  array{contractTabs?: mixed}  $payload
     * @return array{contractTabs: list<string>}
     */
    public function updateUiPreferences(User $user, array $payload): array
    {
        $current = is_array($user->ui_preferences) ? $user->ui_preferences : [];

        if (array_key_exists('contractTabs', $payload)) {
            $current['contractTabs'] = ContractTabOrder::normalize($payload['contractTabs']);
        }

        $user->ui_preferences = $current;
        $user->save();

        return $this->presentUiPreferences($user->fresh() ?? $user);
    }

    /**
     * @return array{contractTabs: list<string>}
     */
    private function presentUiPreferences(User $user): array
    {
        $raw = is_array($user->ui_preferences) ? $user->ui_preferences : [];

        return [
            'contractTabs' => ContractTabOrder::normalize($raw['contractTabs'] ?? null),
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
