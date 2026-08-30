<?php

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('rechaza la creación de usuario con un rol inválido', function () {
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->postJson('/api/users', [
        'name' => 'Usuario Inválido',
        'email' => 'invalido@example.com',
        'password' => 'password',
        'role' => 'super_admin',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);
});

it('crea un usuario con un rol válido y lo lista con ese rol', function () {
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->postJson('/api/users', [
        'name' => 'Ana Socio',
        'email' => 'ana.socio@example.com',
        'password' => 'password',
        'role' => RoleName::SOCIO_GERENCIA->value,
    ])->assertCreated()
        ->assertJsonPath('data.roles.0', RoleName::SOCIO_GERENCIA->value);

    $this->getJson('/api/users')
        ->assertOk()
        ->assertJsonFragment(['email' => 'ana.socio@example.com']);
});

it('permite editar el rol de un usuario con sync y no acumula roles', function () {
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);
    $target = User::factory()->create();
    $target->assignRole(RoleName::SOCIO_GERENCIA->value);

    $this->putJson("/api/users/{$target->id}", [
        'name' => 'Ana Editada',
        'email' => $target->email,
        'role' => RoleName::ADMINISTRADOR->value,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Ana Editada')
        ->assertJsonPath('data.roles', [RoleName::ADMINISTRADOR->value]);

    expect($target->fresh()->getRoleNames()->all())->toBe([RoleName::ADMINISTRADOR->value]);
});

it('impide eliminar el último admin_sistema y autoeliminarse', function () {
    $actor = $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->deleteJson("/api/users/{$actor->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user']);

    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole(RoleName::ADMIN_SISTEMA->value);

    $this->deleteJson("/api/users/{$actor->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user']);

    $this->deleteJson("/api/users/{$otherAdmin->id}")
        ->assertOk();

    $this->deleteJson("/api/users/{$actor->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user']);

    $this->putJson("/api/users/{$actor->id}", [
        'role' => RoleName::ADMINISTRADOR->value,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);
});

it('bloquea a socio_gerencia y administrador en todos los endpoints de usuarios', function (string $role) {
    $this->actingAsRole($role);
    $target = User::factory()->create();

    $payload = [
        'name' => 'No debe',
        'email' => 'nodebe@example.com',
        'password' => 'password',
        'role' => RoleName::ADMINISTRADOR->value,
    ];

    $this->getJson('/api/users')->assertForbidden();
    $this->postJson('/api/users', $payload)->assertForbidden();
    $this->putJson("/api/users/{$target->id}", ['name' => 'Editado'])->assertForbidden();
    $this->patchJson("/api/users/{$target->id}", ['name' => 'Editado'])->assertForbidden();
    $this->putJson("/api/users/{$target->id}/role", ['role' => RoleName::ADMINISTRADOR->value])->assertForbidden();
    $this->deleteJson("/api/users/{$target->id}")->assertForbidden();
})->with([
    RoleName::SOCIO_GERENCIA->value,
    RoleName::ADMINISTRADOR->value,
]);
