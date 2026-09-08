<?php

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('registra last_login_at en un login válido y no en uno fallido', function () {
    $user = User::factory()->create([
        'email' => 'login.track@example.com',
        'last_login_at' => null,
    ]);
    $user->assignRole(RoleName::ADMINISTRADOR);

    $this->postJson('/api/login', [
        'email' => 'login.track@example.com',
        'password' => 'wrong-password',
    ])->assertUnprocessable();

    expect($user->fresh()->last_login_at)->toBeNull();

    $this->postJson('/api/login', [
        'email' => 'login.track@example.com',
        'password' => 'password',
    ])->assertOk();

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('devuelve el resumen de usuarios a admin_sistema', function () {
    $actor = User::factory()->create([
        'name' => 'Admin Sistema',
        'email' => 'actor.sistema@example.com',
        'must_change_password' => false,
        'last_login_at' => now()->subHour(),
        'created_at' => now()->subDays(10),
    ]);
    $actor->assignRole(RoleName::ADMIN_SISTEMA);
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value, $actor);

    $administrador = User::factory()->create([
        'name' => 'Ada Admin',
        'email' => 'ada.admin@example.com',
        'must_change_password' => true,
        'last_login_at' => now()->subMinutes(20),
        'created_at' => now()->subDay(),
    ]);
    $administrador->assignRole(RoleName::ADMINISTRADOR);

    $socio = User::factory()->create([
        'name' => 'Sergio Socio',
        'email' => 'sergio.socio@example.com',
        'must_change_password' => false,
        'last_login_at' => null,
        'created_at' => now()->subHours(2),
    ]);
    $socio->assignRole(RoleName::SOCIO_GERENCIA);

    $neverLogged = User::factory()->create([
        'name' => 'Nuevo Sin Acceso',
        'email' => 'nuevo.sinacceso@example.com',
        'must_change_password' => true,
        'last_login_at' => null,
        'created_at' => now()->subMinutes(5),
    ]);
    $neverLogged->assignRole(RoleName::ADMINISTRADOR);

    $deleted = User::factory()->create([
        'name' => 'Borrado',
        'email' => 'borrado@example.com',
        'must_change_password' => true,
        'last_login_at' => now(),
    ]);
    $deleted->assignRole(RoleName::ADMINISTRADOR);
    $deleted->delete();

    $response = $this->getJson('/api/dashboard/system-users')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.total_users', 4)
        ->assertJsonPath('data.pending_password_change', 2)
        ->assertJsonPath('data.by_role.administrador', 2)
        ->assertJsonPath('data.by_role.admin_sistema', 1)
        ->assertJsonPath('data.by_role.socio_gerencia', 1);

    $logins = $response->json('data.recent_logins');
    expect($logins)->toHaveCount(2)
        ->and(collect($logins)->pluck('email')->all())->toBe([
            'ada.admin@example.com',
            'actor.sistema@example.com',
        ])
        ->and(collect($logins)->pluck('email'))->not->toContain('sergio.socio@example.com')
        ->and(collect($logins)->pluck('email'))->not->toContain('borrado@example.com');

    $created = $response->json('data.recently_created');
    expect($created[0]['email'])->toBe('nuevo.sinacceso@example.com')
        ->and(collect($created)->pluck('email'))->not->toContain('borrado@example.com');
});

it('niega el resumen de usuarios a administrador y socio_gerencia', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);
    $this->getJson('/api/dashboard/system-users')->assertForbidden();

    $this->actingAsRole(RoleName::SOCIO_GERENCIA->value);
    $this->getJson('/api/dashboard/system-users')->assertForbidden();
});

it('admin_sistema sigue recibiendo 403 en un endpoint de negocio', function () {
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->getJson('/api/dashboard/system-users')->assertOk();
    $this->getJson('/api/dashboard/cartera-mora')->assertForbidden();
    $this->getJson('/api/projects')->assertForbidden();
});
