<?php

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('permite login con el correo en cualquier combinación de mayúsculas', function () {
    $user = User::factory()->create([
        'email' => 'santiago@empresa.test',
        'password' => Hash::make('password'),
    ]);
    $user->assignRole(RoleName::ADMINISTRADOR->value);

    $this->postJson('/api/login', [
        'email' => 'Santiago@Empresa.TEST',
        'password' => 'password',
    ])->assertOk()
        ->assertJsonPath('data.user.email', 'santiago@empresa.test')
        ->assertJsonPath('data.access_token', fn ($token) => is_string($token) && $token !== '');
});

it('encuentra un usuario legado cuyo correo quedó guardado con mayúsculas', function () {
    $user = User::factory()->create([
        'email' => 'santiago@empresa.test',
        'password' => Hash::make('password'),
    ]);
    $user->assignRole(RoleName::ADMINISTRADOR->value);

    DB::table('users')->where('id', $user->id)->update(['email' => 'Santiago@empresa.test']);

    $this->postJson('/api/login', [
        'email' => 'santiago@empresa.test',
        'password' => 'password',
    ])->assertOk()
        ->assertJsonPath('data.user.id', $user->id);
});

it('guarda el correo en minúsculas al crear y al editar', function () {
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $created = $this->postJson('/api/users', [
        'name' => 'Santiago',
        'email' => 'Santiago@Empresa.TEST',
        'password' => 'password12',
        'role' => RoleName::ADMINISTRADOR->value,
    ])->assertCreated()
        ->assertJsonPath('data.email', 'santiago@empresa.test');

    $userId = $created->json('data.id');
    expect(User::query()->find($userId)?->email)->toBe('santiago@empresa.test');

    $this->putJson("/api/users/{$userId}", [
        'email' => 'Santiago.Editado@Empresa.TEST',
    ])->assertOk()
        ->assertJsonPath('data.email', 'santiago.editado@empresa.test');

    expect(User::query()->find($userId)?->email)->toBe('santiago.editado@empresa.test');
});

it('rechaza crear Juan@x.com si ya existe juan@x.com', function () {
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);
    User::factory()->create(['email' => 'juan@x.com']);

    $this->postJson('/api/users', [
        'name' => 'Juan Duplicado',
        'email' => 'Juan@X.com',
        'password' => 'password12',
        'role' => RoleName::ADMINISTRADOR->value,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email'])
        ->assertJsonPath('errors.email.0', 'Este correo ya está registrado.');
});

it('rechaza editar a un correo que otro usuario ya tiene con distinta capitalización', function () {
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);
    User::factory()->create(['email' => 'juan@x.com']);
    $target = User::factory()->create(['email' => 'otro@x.com']);
    $target->assignRole(RoleName::ADMINISTRADOR->value);

    $this->putJson("/api/users/{$target->id}", [
        'email' => 'Juan@X.com',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('crea por artisan con correo mixto y rechaza el duplicado en otra capitalización', function () {
    $this->artisan('user:create', [
        'name' => 'Ada Admin',
        'email' => 'Ada@Empresa.TEST',
        'role' => RoleName::ADMIN_SISTEMA->value,
    ])
        ->expectsQuestion('Contraseña', 'secreto99')
        ->expectsQuestion('Confirmar contraseña', 'secreto99')
        ->assertSuccessful();

    expect(User::query()->where('email', 'ada@empresa.test')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'Ada@Empresa.TEST')->exists())->toBeFalse();

    $this->artisan('user:create', [
        'name' => 'Ada Otra',
        'email' => 'ADA@empresa.test',
        'role' => RoleName::ADMINISTRADOR->value,
    ])->assertFailed();
});
