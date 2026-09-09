<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\CreateUserDTO;
use App\Enums\RoleName;
use App\Models\User;
use App\Services\Security\UserService;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class CreateUserCommand extends Command
{
    protected $signature = 'user:create {name} {email} {role}';

    protected $description = 'Crea un usuario con rol. La contraseña se pide por prompt y no queda en el código.';

    public function handle(UserService $userService): int
    {
        $name = trim((string) $this->argument('name'));
        $email = User::normalizeEmail(trim((string) $this->argument('email'))) ?? '';
        $role = trim((string) $this->argument('role'));

        if ($name === '' || $email === '') {
            $this->error('Nombre y correo son obligatorios.');

            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('El correo no es válido.');

            return self::FAILURE;
        }

        if (! in_array($role, RoleName::values(), true)) {
            $this->error('Rol inválido. Usa: '.implode(', ', RoleName::values()));

            return self::FAILURE;
        }

        if (Role::query()->where('name', $role)->where('guard_name', 'web')->doesntExist()) {
            $this->error('Ese rol no existe en la base. Ejecuta `php artisan db:seed --class=RolesAndPermissionsSeeder`.');

            return self::FAILURE;
        }

        if (User::emailIsTaken($email)) {
            $this->error("Ya existe un usuario con el correo {$email}.");

            return self::FAILURE;
        }

        $password = (string) $this->secret('Contraseña');
        $confirm = (string) $this->secret('Confirmar contraseña');

        if ($password === '' || strlen($password) < 8) {
            $this->error('La contraseña debe tener al menos 8 caracteres.');

            return self::FAILURE;
        }

        if ($password !== $confirm) {
            $this->error('Las contraseñas no coinciden.');

            return self::FAILURE;
        }

        $userService->createUser(new CreateUserDTO(
            name: $name,
            email: $email,
            password: $password,
            role: $role,
        ));

        $this->info("Usuario {$email} creado con rol {$role}.");

        return self::SUCCESS;
    }
}
