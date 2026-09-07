<?php

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('desbloquea a un usuario marcado y le permite volver a entrar', function () {
    $user = User::factory()->create([
        'email' => 'bloqueado@example.com',
        'password' => Hash::make('password'),
        'must_change_password' => true,
    ]);
    $user->assignRole(RoleName::ADMINISTRADOR->value);

    Sanctum::actingAs($user);
    $this->getJson('/api/contracts')->assertForbidden();

    $this->artisan('user:unlock-password', ['--email' => 'bloqueado@example.com'])
        ->assertSuccessful()
        ->expectsOutput('Usuario bloqueado@example.com desbloqueado. Ya puede entrar con su contraseña actual.');

    expect($user->fresh()->must_change_password)->toBeFalse();

    Sanctum::actingAs($user->fresh());
    $this->getJson('/api/contracts')->assertOk();
});

it('desbloquea a todos con --all y exige email o all', function () {
    $one = User::factory()->create([
        'email' => 'uno@example.com',
        'must_change_password' => true,
    ]);
    $two = User::factory()->create([
        'email' => 'dos@example.com',
        'must_change_password' => true,
    ]);

    $this->artisan('user:unlock-password')->assertFailed();
    $this->artisan('user:unlock-password', [
        '--email' => 'uno@example.com',
        '--all' => true,
    ])->assertFailed();

    $this->artisan('user:unlock-password', ['--all' => true])->assertSuccessful();

    expect($one->fresh()->must_change_password)->toBeFalse()
        ->and($two->fresh()->must_change_password)->toBeFalse();
});
