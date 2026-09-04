<?php

use App\Enums\RoleName;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->customer = Customer::factory()->create([
        'name' => 'BuscaAlpha Cliente',
        'document_number' => '99001122',
    ]);

    $this->project = Project::query()->create([
        'name' => 'Proyecto BuscaAlpha',
        'description' => 'Fixture de búsqueda global',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $this->lot = Lot::factory()->create([
        'project_id' => $this->project->id,
        'number' => 'L-BuscaAlpha',
    ]);

    $this->contract = Contract::factory()->create([
        'customer_id' => $this->customer->id,
        'lot_id' => $this->lot->id,
        'contract_number' => 'CTR-BuscaAlpha',
    ]);

    Customer::factory()->create([
        'name' => 'W',
        'document_number' => '111000111',
    ]);
});

it('permite al administrador ver clientes, contratos y lotes coincidentes', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $response = $this->getJson('/api/search?q=BuscaAlpha')
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $data = $response->json('data');

    expect($data['clients'])->toHaveCount(1)
        ->and($data['clients'][0]['name'])->toBe('BuscaAlpha Cliente')
        ->and($data['clients'][0]['document_number'])->toBe('99001122')
        ->and($data['contracts'])->toHaveCount(1)
        ->and($data['contracts'][0]['contract_number'])->toBe('CTR-BuscaAlpha')
        ->and($data['contracts'][0]['customer_name'])->toBe('BuscaAlpha Cliente')
        ->and($data['lots'])->toHaveCount(1)
        ->and($data['lots'][0]['number'])->toBe('L-BuscaAlpha')
        ->and($data['lots'][0]['project_name'])->toBe('Proyecto BuscaAlpha');
});

it('encuentra el contrato al buscar por el nombre del co-titular', function () {
    $coTitular = Customer::factory()->create([
        'name' => 'BuscaCoTitular Extra',
        'document_number' => '55667788',
    ]);

    $this->contract->syncHolders($this->customer->id, [$coTitular->id]);

    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $data = $this->getJson('/api/search?q=BuscaCoTitular')
        ->assertOk()
        ->json('data');

    expect($data['contracts'])->toHaveCount(1)
        ->and($data['contracts'][0]['contract_number'])->toBe('CTR-BuscaAlpha')
        ->and($data['contracts'][0]['customer_name'])->toContain('BuscaCoTitular Extra');
});

it('permite a socio_gerencia ver contratos y lotes, pero clientes siempre vacío', function () {
    $this->actingAsRole(RoleName::SOCIO_GERENCIA->value);

    $data = $this->getJson('/api/search?q=BuscaAlpha')
        ->assertOk()
        ->json('data');

    expect($data['clients'])->toBe([])
        ->and($data['contracts'])->toHaveCount(1)
        ->and($data['lots'])->toHaveCount(1);
});

it('devuelve las tres listas vacías a admin_sistema aunque haya coincidencias', function () {
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $data = $this->getJson('/api/search?q=BuscaAlpha')
        ->assertOk()
        ->json('data');

    expect($data['clients'])->toBe([])
        ->and($data['contracts'])->toBe([])
        ->and($data['lots'])->toBe([]);
});

it('no dispara búsqueda real cuando la query tiene un solo carácter', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'from "customers"')
            || str_contains($sql, 'from "contracts"')
            || str_contains($sql, 'from "lots"')) {
            $queries[] = $sql;
        }
    });

    $data = $this->getJson('/api/search?q=W')
        ->assertOk()
        ->json('data');

    expect($data['clients'])->toBe([])
        ->and($data['contracts'])->toBe([])
        ->and($data['lots'])->toBe([])
        ->and($queries)->toBe([]);
});
