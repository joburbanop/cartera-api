<?php

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function markedUser(array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'password' => Hash::make('password'),
        'must_change_password' => true,
    ], $overrides));
    $user->assignRole(RoleName::ADMINISTRADOR->value);

    return $user;
}

it('un usuario marcado no puede consumir otras rutas del API', function () {
    Sanctum::actingAs(markedUser());

    $this->getJson('/api/contracts')
        ->assertForbidden()
        ->assertJsonPath('message', 'Debes cambiar tu contraseña antes de continuar.')
        ->assertJsonPath('errors.code', 'password_change_required');

    $this->getJson('/api/lots')->assertForbidden();
    $this->getJson('/api/users')->assertForbidden();
});

it('un usuario marcado sí puede consultar /me, cambiar su contraseña y cerrar sesión', function () {
    $user = markedUser();
    Sanctum::actingAs($user);

    $this->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.user.must_change_password', true)
        ->assertJsonPath('data.user.password_changed_at', null);

    $changed = $this->putJson('/api/me/password', [
        'current_password' => 'password',
        'password' => 'nuevaClave1',
        'password_confirmation' => 'nuevaClave1',
    ])->assertOk()
        ->assertJsonPath('data.must_change_password', false);

    expect($changed->json('data.password_changed_at'))->not->toBeNull()
        ->and($user->fresh()->must_change_password)->toBeFalse()
        ->and($user->fresh()->password_changed_at)->not->toBeNull()
        ->and(Hash::check('nuevaClave1', $user->fresh()->password))->toBeTrue();

    Sanctum::actingAs($user->fresh());
    $this->postJson('/api/logout')->assertOk();
});

it('un usuario marcado puede cerrar sesión sin haber cambiado la contraseña', function () {
    Sanctum::actingAs(markedUser());

    $this->postJson('/api/logout')->assertOk();
});

it('después del cambio el usuario accede normalmente', function () {
    $user = markedUser();
    Sanctum::actingAs($user);

    $this->putJson('/api/me/password', [
        'current_password' => 'password',
        'password' => 'nuevaClave1',
        'password_confirmation' => 'nuevaClave1',
    ])->assertOk();

    Sanctum::actingAs($user->fresh());

    $this->getJson('/api/contracts')->assertOk();
    $this->getJson('/api/me')->assertJsonPath('data.user.must_change_password', false);
});

it('rechaza una nueva contraseña igual a la anterior o menor de 8 caracteres', function () {
    Sanctum::actingAs(markedUser());

    $this->putJson('/api/me/password', [
        'current_password' => 'password',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['password'])
        ->assertJsonPath('errors.password.0', 'La nueva contraseña debe ser diferente a la actual.');

    $this->putJson('/api/me/password', [
        'current_password' => 'password',
        'password' => 'corta',
        'password_confirmation' => 'corta',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['password'])
        ->assertJsonPath('errors.password.0', 'La contraseña debe tener al menos 8 caracteres.');

    $this->putJson('/api/me/password', [
        'current_password' => 'equivocada',
        'password' => 'nuevaClave1',
        'password_confirmation' => 'nuevaClave1',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password'])
        ->assertJsonPath('errors.current_password.0', 'La contraseña actual no es correcta.');
});

it('el alta de usuario y user:create dejan la marca activa', function () {
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->postJson('/api/users', [
        'name' => 'Nuevo Marcado',
        'email' => 'nuevo.marcado@example.com',
        'password' => 'password',
        'role' => RoleName::ADMINISTRADOR->value,
    ])->assertCreated()
        ->assertJsonPath('data.must_change_password', true);

    expect(User::query()->where('email', 'nuevo.marcado@example.com')->value('must_change_password'))->toBeTrue()
        ->and(User::query()->where('email', 'nuevo.marcado@example.com')->value('password_changed_at'))->toBeNull();

    $this->artisan('user:create', [
        'name' => 'Ada Marcada',
        'email' => 'ada.marcada@example.com',
        'role' => RoleName::ADMINISTRADOR->value,
    ])
        ->expectsQuestion('Contraseña', 'secreto99')
        ->expectsQuestion('Confirmar contraseña', 'secreto99')
        ->assertSuccessful();

    expect(User::query()->where('email', 'ada.marcada@example.com')->value('must_change_password'))->toBeTrue()
        ->and(User::query()->where('email', 'ada.marcada@example.com')->value('password_changed_at'))->toBeNull();
});

it('un admin que resetea la contraseña de otro lo deja marcado y conserva password_changed_at', function () {
    $actor = $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);
    $previousChange = now()->subDays(4);
    $target = User::factory()->create([
        'must_change_password' => false,
        'password' => Hash::make('password'),
        'password_changed_at' => $previousChange,
    ]);
    $target->assignRole(RoleName::ADMINISTRADOR->value);

    $this->putJson("/api/users/{$target->id}", [
        'password' => 'resetada1',
    ])->assertOk()
        ->assertJsonPath('data.must_change_password', true);

    $fresh = $target->fresh();
    expect($fresh->must_change_password)->toBeTrue()
        ->and($fresh->password_changed_at?->format('Y-m-d H:i:s'))->toBe($previousChange->format('Y-m-d H:i:s'))
        ->and(Hash::check('resetada1', $fresh->password))->toBeTrue()
        ->and($actor->fresh()->must_change_password)->toBeFalse();
});

it('un usuario que cambia su propia contraseña no queda marcado', function () {
    $actor = $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);
    $actor->forceFill(['must_change_password' => false])->save();

    $this->putJson("/api/users/{$actor->id}", [
        'password' => 'miNueva99',
    ])->assertOk()
        ->assertJsonPath('data.must_change_password', false);

    expect($actor->fresh()->must_change_password)->toBeFalse()
        ->and($actor->fresh()->password_changed_at)->not->toBeNull()
        ->and(Hash::check('miNueva99', $actor->fresh()->password))->toBeTrue();

    $other = markedUser(['email' => 'otro.propio@example.com']);
    Sanctum::actingAs($other);

    $this->putJson('/api/me/password', [
        'current_password' => 'password',
        'password' => 'claveNueva9',
        'password_confirmation' => 'claveNueva9',
    ])->assertOk()
        ->assertJsonPath('data.must_change_password', false);

    expect($other->fresh()->must_change_password)->toBeFalse()
        ->and($other->fresh()->password_changed_at)->not->toBeNull();
});

it('el reset de alguien que nunca cambió su clave deja password_changed_at en null', function () {
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);
    $target = markedUser([
        'email' => 'nunca.cambio@example.com',
        'password_changed_at' => null,
    ]);

    $this->putJson("/api/users/{$target->id}", [
        'password' => 'resetada1',
    ])->assertOk()
        ->assertJsonPath('data.must_change_password', true)
        ->assertJsonPath('data.password_changed_at', null);

    expect($target->fresh()->password_changed_at)->toBeNull();
});

it('el login expone must_change_password y password_changed_at', function () {
    markedUser([
        'email' => 'login.marcado@example.com',
    ]);

    $this->postJson('/api/login', [
        'email' => 'login.marcado@example.com',
        'password' => 'password',
    ])->assertOk()
        ->assertJsonPath('data.user.must_change_password', true)
        ->assertJsonPath('data.user.password_changed_at', null);

    $resetAt = now()->subDay();
    $resetUser = markedUser([
        'email' => 'login.reset@example.com',
        'password_changed_at' => $resetAt,
    ]);

    $this->postJson('/api/login', [
        'email' => 'login.reset@example.com',
        'password' => 'password',
    ])->assertOk()
        ->assertJsonPath('data.user.must_change_password', true)
        ->assertJsonPath('data.user.password_changed_at', $resetUser->fresh()->password_changed_at?->toIso8601String());
});

it('la migración deja consistentes a los usuarios existentes', function () {
    $stillMarked = User::factory()->create([
        'must_change_password' => true,
        'password_changed_at' => null,
    ]);
    $alreadyUnlocked = User::factory()->create([
        'must_change_password' => false,
        'password_changed_at' => null,
    ]);

    DB::table('users')
        ->where('must_change_password', false)
        ->whereNull('password_changed_at')
        ->update(['password_changed_at' => now()]);

    expect($stillMarked->fresh()->must_change_password)->toBeTrue()
        ->and($stillMarked->fresh()->password_changed_at)->toBeNull()
        ->and($alreadyUnlocked->fresh()->must_change_password)->toBeFalse()
        ->and($alreadyUnlocked->fresh()->password_changed_at)->not->toBeNull();
});
