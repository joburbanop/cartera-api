<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Security\UserService;
use Illuminate\Console\Command;

class UnlockPasswordCommand extends Command
{
    protected $signature = 'user:unlock-password
                            {--email= : Correo del usuario a desbloquear}
                            {--all : Desbloquea a todos los usuarios}';

    protected $description = 'Quita la marca must_change_password para recuperar el acceso si el cambio de contraseña falla.';

    public function handle(UserService $userService): int
    {
        $email = strtolower(trim((string) $this->option('email')));
        $all = (bool) $this->option('all');

        if ($all === ($email !== '')) {
            $this->error('Indica --email=correo o --all, no ambos ni ninguno.');

            return self::FAILURE;
        }

        if ($all) {
            $users = User::query()->where('must_change_password', true)->get();

            foreach ($users as $user) {
                $userService->clearMustChangePassword($user);
            }

            $this->info($users->count() === 1
                ? 'Se desbloqueó 1 usuario.'
                : "Se desbloquearon {$users->count()} usuarios.");

            return self::SUCCESS;
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No existe un usuario con el correo {$email}.");

            return self::FAILURE;
        }

        $userService->clearMustChangePassword($user);
        $this->info("Usuario {$email} desbloqueado. Ya puede entrar con su contraseña actual.");

        return self::SUCCESS;
    }
}
