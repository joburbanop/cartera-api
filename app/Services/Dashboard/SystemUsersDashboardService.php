<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Collection;

class SystemUsersDashboardService
{
    private const LIST_LIMIT = 8;

    /**
     * @return array{
     *     total_users: int,
     *     pending_password_change: int,
     *     by_role: array<string, int>,
     *     recent_logins: list<array{id: int, name: string, email: string, roles: list<string>, last_login_at: string}>,
     *     recently_created: list<array{id: int, name: string, email: string, roles: list<string>, created_at: string}>
     * }
     */
    public function summary(): array
    {
        $activeUsers = User::query()->whereNull('deleted_at');

        return [
            'total_users' => (clone $activeUsers)->count(),
            'pending_password_change' => (clone $activeUsers)->where('must_change_password', true)->count(),
            'by_role' => $this->countByRole(),
            'recent_logins' => $this->presentAccessList(
                User::query()
                    ->with('roles')
                    ->whereNotNull('last_login_at')
                    ->orderByDesc('last_login_at')
                    ->limit(self::LIST_LIMIT)
                    ->get(),
                'last_login_at'
            ),
            'recently_created' => $this->presentAccessList(
                User::query()
                    ->with('roles')
                    ->orderByDesc('created_at')
                    ->limit(self::LIST_LIMIT)
                    ->get(),
                'created_at'
            ),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function countByRole(): array
    {
        $counts = [
            RoleName::ADMINISTRADOR->value => 0,
            RoleName::ADMIN_SISTEMA->value => 0,
            RoleName::SOCIO_GERENCIA->value => 0,
        ];

        foreach (RoleName::values() as $role) {
            $counts[$role] = User::query()->role($role)->count();
        }

        return $counts;
    }

    /**
     * @param  Collection<int, User>  $users
     * @return list<array{id: int, name: string, email: string, roles: list<string>, last_login_at?: string, created_at?: string}>
     */
    private function presentAccessList(Collection $users, string $dateField): array
    {
        return $users->map(function (User $user) use ($dateField): array {
            $date = $user->{$dateField};

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->values()->all(),
                $dateField => $date?->toIso8601String(),
            ];
        })->all();
    }
}
