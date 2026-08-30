<?php

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\BankAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    Sanctum::actingAs($user);

    return $user;
}

it('crea los 3 roles con sus permisos y sin cruces indebidos', function () {
    foreach (RoleName::values() as $roleName) {
        expect(Role::query()->where('name', $roleName)->exists())->toBeTrue();
    }

    $socio = Role::findByName(RoleName::SOCIO_GERENCIA->value);
    expect($socio->hasPermissionTo(PermissionName::PROJECTS_VIEW->value))->toBeTrue();
    expect($socio->hasPermissionTo(PermissionName::TRANSACTIONS_VIEW->value))->toBeTrue();
    expect($socio->hasPermissionTo(PermissionName::PROJECTS_MANAGE->value))->toBeFalse();
    expect($socio->hasPermissionTo(PermissionName::USERS_MANAGE->value))->toBeFalse();

    $adminSistema = Role::findByName(RoleName::ADMIN_SISTEMA->value);
    expect($adminSistema->hasPermissionTo(PermissionName::USERS_MANAGE->value))->toBeTrue();
    expect($adminSistema->hasPermissionTo(PermissionName::ROLES_MANAGE->value))->toBeTrue();
    expect($adminSistema->hasPermissionTo(PermissionName::PROJECTS_MANAGE->value))->toBeFalse();

    $administrador = Role::findByName(RoleName::ADMINISTRADOR->value);
    expect($administrador->hasPermissionTo(PermissionName::PROJECTS_MANAGE->value))->toBeTrue();
    expect($administrador->hasPermissionTo(PermissionName::PAYMENTS_REGISTER->value))->toBeTrue();
    expect($administrador->hasPermissionTo(PermissionName::USERS_MANAGE->value))->toBeFalse();
    expect($administrador->hasPermissionTo(PermissionName::ROLES_MANAGE->value))->toBeFalse();
});

it('responde 401 sin sesión y 403 si el usuario no tiene el permiso', function () {
    $this->postJson('/api/projects', [
        'name' => 'Sin sesión',
        'location' => 'Bogotá',
        'bank_account_ids' => [1],
    ])->assertUnauthorized();

    userWithRole(RoleName::SOCIO_GERENCIA->value);

    $this->postJson('/api/projects', [
        'name' => 'Sin permiso',
        'location' => 'Bogotá',
        'bank_account_ids' => [1],
    ])->assertForbidden();
});

it('impide que socio_gerencia cree un proyecto', function () {
    userWithRole(RoleName::SOCIO_GERENCIA->value);

    $this->postJson('/api/projects', [
        'name' => 'Proyecto Socio',
        'location' => 'Bogotá',
        'bank_account_ids' => [1],
    ])->assertForbidden();
});

it('permite que administrador cree un proyecto', function () {
    $user = userWithRole(RoleName::ADMINISTRADOR->value);

    $account = BankAccount::query()->create([
        'bank_name' => 'Bancolombia',
        'account_number' => '9988776655',
        'account_type' => 'savings',
        'holder_name' => 'Constructora QA',
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $this->postJson('/api/projects', [
        'name' => 'Proyecto Administrador',
        'location' => 'Bogotá',
        'bank_account_ids' => [$account->id],
    ])->assertCreated();
});

it('permite que admin_sistema cree un usuario pero no un proyecto', function () {
    userWithRole(RoleName::ADMIN_SISTEMA->value);

    $this->postJson('/api/users', [
        'name' => 'Usuario Nuevo',
        'email' => 'nuevo.sistema@example.com',
        'password' => 'password',
        'role' => RoleName::ADMINISTRADOR->value,
    ])->assertCreated()
        ->assertJsonPath('data.email', 'nuevo.sistema@example.com')
        ->assertJsonPath('data.roles.0', RoleName::ADMINISTRADOR->value);

    $this->postJson('/api/projects', [
        'name' => 'Proyecto Sistema',
        'location' => 'Bogotá',
        'bank_account_ids' => [1],
    ])->assertForbidden();
});

it('autoriza GET de proyectos con un token Sanctum real', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleName::ADMINISTRADOR->value);
    $token = $user->createToken('auth_token')->plainTextToken;

    $this->getJson('/api/projects', ['Authorization' => "Bearer {$token}"])
        ->assertOk();
});

it('responde 403 y no 500 cuando el token es válido pero no hay permiso', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleName::SOCIO_GERENCIA->value);
    $token = $user->createToken('auth_token')->plainTextToken;

    $this->postJson('/api/projects', [
        'name' => 'Token Socio',
        'location' => 'Bogotá',
        'bank_account_ids' => [1],
    ], ['Authorization' => "Bearer {$token}"])
        ->assertForbidden()
        ->assertJsonPath('status', 'error');
});

it('expone los roles en login y en /me', function () {
    $user = User::factory()->create([
        'email' => 'login.roles@example.com',
        'password' => 'password',
    ]);
    $user->assignRole(RoleName::ADMINISTRADOR->value);

    $this->postJson('/api/login', [
        'email' => 'login.roles@example.com',
        'password' => 'password',
    ])->assertOk()
        ->assertJsonPath('data.roles.0', RoleName::ADMINISTRADOR->value)
        ->assertJsonPath('data.user.roles.0', RoleName::ADMINISTRADOR->value)
        ->assertJsonStructure(['data' => ['access_token', 'roles', 'user']]);

    Sanctum::actingAs($user);

    $this->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.roles.0', RoleName::ADMINISTRADOR->value);
});
