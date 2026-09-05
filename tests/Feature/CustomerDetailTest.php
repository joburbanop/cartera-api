<?php

use App\Enums\RoleName;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('permite al administrador ver el detalle del cliente con sus contratos', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $project = Project::query()->create([
        'name' => 'Proyecto Ficha',
        'description' => 'Fixture detalle cliente',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::factory()->create([
        'name' => 'Cliente Ficha',
        'document_number' => '1122334455',
        'phone' => '3001112233',
        'email' => 'ficha@example.com',
        'address' => 'Calle 10 # 4-50',
        'city' => 'Medellín',
    ]);

    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => 'L-Ficha-01',
    ]);

    Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'contract_number' => 'PROM-FICHA-001',
        'status' => 'activo',
    ]);

    $this->getJson("/api/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $customer->id)
        ->assertJsonPath('data.nombre', 'Cliente Ficha')
        ->assertJsonPath('data.name', 'Cliente Ficha')
        ->assertJsonPath('data.documento', '1122334455')
        ->assertJsonPath('data.contracts.0.contract_number', 'PROM-FICHA-001')
        ->assertJsonPath('data.contracts.0.status', 'activo')
        ->assertJsonPath('data.contracts.0.lot.number', 'L-Ficha-01')
        ->assertJsonPath('data.contracts.0.project.name', 'Proyecto Ficha');
});

it('incluye en la ficha del co-titular el contrato asociado por pivote', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $principal = Customer::factory()->create(['name' => 'Principal Ficha']);
    $coTitular = Customer::factory()->create(['name' => 'Co Ficha']);
    $project = Project::query()->create([
        'name' => 'Proyecto Co Ficha',
        'description' => 'Fixture',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => 'L-CO-FICHA',
    ]);

    $contract = Contract::factory()->create([
        'customer_id' => $principal->id,
        'lot_id' => $lot->id,
        'contract_number' => 'PROM-CO-FICHA',
        'status' => 'activo',
    ]);
    $contract->syncHolders($principal->id, [$coTitular->id]);

    $this->getJson("/api/customers/{$coTitular->id}")
        ->assertOk()
        ->assertJsonPath('data.contracts.0.contract_number', 'PROM-CO-FICHA');
});

it('responde 404 si el cliente no existe', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $this->getJson('/api/customers/999999')->assertNotFound();
});

it('niega el detalle a socio_gerencia igual que el listado', function () {
    $this->actingAsRole(RoleName::SOCIO_GERENCIA->value);

    $customer = Customer::factory()->create();

    $this->getJson('/api/customers')->assertForbidden();
    $this->getJson("/api/customers/{$customer->id}")->assertForbidden();
});
