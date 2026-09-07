<?php

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('crea usuarios de demostración fuera de producción', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->where('email', 'admin@admin.com')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'socio@cartera.test')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'sistema@cartera.test')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'test@example.com')->exists())->toBeTrue();

    $admin = User::query()->where('email', 'admin@admin.com')->first();
    expect(Hash::check('password', $admin->password))->toBeTrue()
        ->and($admin->hasRole(RoleName::ADMINISTRADOR->value))->toBeTrue();
});

it('no crea usuarios de demostración en producción', function () {
    $this->app['env'] = 'production';

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(User::query()->count())->toBe(0);
    expect(\Spatie\Permission\Models\Role::query()->where('name', RoleName::ADMINISTRADOR->value)->exists())->toBeTrue();
});

it('crea un usuario por artisan con contraseña por prompt', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->artisan('user:create', [
        'name' => 'Ada Admin',
        'email' => 'ada@empresa.test',
        'role' => RoleName::ADMIN_SISTEMA->value,
    ])
        ->expectsQuestion('Contraseña', 'secreto99')
        ->expectsQuestion('Confirmar contraseña', 'secreto99')
        ->assertSuccessful();

    $user = User::query()->where('email', 'ada@empresa.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Ada Admin')
        ->and($user->hasRole(RoleName::ADMIN_SISTEMA->value))->toBeTrue()
        ->and(Hash::check('secreto99', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeTrue();
});

it('rechaza user:create con rol inválido o correo duplicado', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->artisan('user:create', [
        'name' => 'X',
        'email' => 'x@empresa.test',
        'role' => 'super_admin',
    ])->assertFailed();

    User::factory()->create(['email' => 'ya@empresa.test']);

    $this->artisan('user:create', [
        'name' => 'Ya Existe',
        'email' => 'ya@empresa.test',
        'role' => RoleName::ADMINISTRADOR->value,
    ])->assertFailed();
});
