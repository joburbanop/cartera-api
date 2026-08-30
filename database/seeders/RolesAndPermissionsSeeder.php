<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (PermissionName::all() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $socio = Role::findOrCreate(RoleName::SOCIO_GERENCIA->value, 'web');
        $socio->syncPermissions(array_map(
            static fn (PermissionName $permission): string => $permission->value,
            PermissionName::socioGerencia(),
        ));

        $adminSistema = Role::findOrCreate(RoleName::ADMIN_SISTEMA->value, 'web');
        $adminSistema->syncPermissions(array_map(
            static fn (PermissionName $permission): string => $permission->value,
            PermissionName::adminSistema(),
        ));

        $administrador = Role::findOrCreate(RoleName::ADMINISTRADOR->value, 'web');
        $administrador->syncPermissions(array_map(
            static fn (PermissionName $permission): string => $permission->value,
            PermissionName::administrador(),
        ));
    }
}
