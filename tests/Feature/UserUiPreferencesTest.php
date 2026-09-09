<?php

use App\Enums\RoleName;
use App\Models\User;
use App\Support\ContractTabOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('expone el orden por defecto en login y en /me', function () {
    $user = User::factory()->create([
        'email' => 'prefs@example.com',
        'password' => 'password',
    ]);
    $user->assignRole(RoleName::ADMINISTRADOR->value);

    $this->postJson('/api/login', [
        'email' => 'prefs@example.com',
        'password' => 'password',
    ])->assertOk()
        ->assertJsonPath('data.user.ui_preferences.contractTabs', ContractTabOrder::DEFAULT);

    Sanctum::actingAs($user);

    $this->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.user.ui_preferences.contractTabs', ContractTabOrder::DEFAULT);
});

it('guarda el orden de pestañas y descarta ids desconocidos', function () {
    $user = $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $this->patchJson('/api/me/preferences', [
        'contractTabs' => ['hoja-vida', 'inventado', 'bitacora-contrato', 'amortizacion'],
    ])->assertOk()
        ->assertJsonPath('data.ui_preferences.contractTabs', [
            'hoja-vida',
            'bitacora-contrato',
            'amortizacion',
            'promesa',
            'bitacora-cliente',
        ]);

    $this->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.user.ui_preferences.contractTabs.0', 'hoja-vida')
        ->assertJsonPath('data.user.ui_preferences.contractTabs.3', 'promesa');
});

it('rechaza un body sin contractTabs', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $this->patchJson('/api/me/preferences', [
        'other' => true,
    ])->assertUnprocessable();
});

it('exige autenticación para guardar preferencias', function () {
    $this->patchJson('/api/me/preferences', [
        'contractTabs' => ContractTabOrder::DEFAULT,
    ])->assertUnauthorized();
});
