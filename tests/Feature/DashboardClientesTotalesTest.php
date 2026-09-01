<?php

use App\Enums\RoleName;
use App\Models\Customer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('devuelve solo el conteo agregado a socio_gerencia y no el listado de clientes', function () {
    Customer::factory()->count(3)->create();

    $this->actingAsRole(RoleName::SOCIO_GERENCIA->value);

    $response = $this->getJson('/api/dashboard/clientes-totales')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.total_clientes', 3);

    expect(array_keys($response->json('data')))->toBe(['total_clientes']);

    $this->getJson('/api/customers')->assertForbidden();
});

it('permite a administrador consultar el resumen y niega a admin_sistema', function () {
    Customer::factory()->create();

    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $this->getJson('/api/dashboard/clientes-totales')
        ->assertOk()
        ->assertJsonPath('data.total_clientes', 1);

    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->getJson('/api/dashboard/clientes-totales')->assertForbidden();
});
