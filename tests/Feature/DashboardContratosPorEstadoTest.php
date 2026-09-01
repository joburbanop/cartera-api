<?php

use App\Enums\ContractStatus;
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

    $this->project = Project::query()->create([
        'name' => 'Proyecto Contratos Estado',
        'description' => 'Fixture contratos por estado',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $this->customer = Customer::factory()->create();
});

function createContractWithStatus(string $status, string $lotNumber, string $contractNumber): Contract
{
    $lot = Lot::factory()->create([
        'project_id' => test()->project->id,
        'number' => $lotNumber,
    ]);

    return Contract::factory()->create([
        'customer_id' => test()->customer->id,
        'lot_id' => $lot->id,
        'contract_number' => $contractNumber,
        'status' => $status,
    ]);
}

it('cuenta contratos agrupados por los valores reales de ContractStatus', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    createContractWithStatus(ContractStatus::ACTIVO->value, 'L-CE-01', 'CTR-CE-001');
    createContractWithStatus(ContractStatus::ACTIVO->value, 'L-CE-02', 'CTR-CE-002');
    createContractWithStatus(ContractStatus::PREVENTA_INACTIVA->value, 'L-CE-03', 'CTR-CE-003');
    createContractWithStatus(ContractStatus::TERMINADO->value, 'L-CE-04', 'CTR-CE-004');
    createContractWithStatus(ContractStatus::RESCINDIDO->value, 'L-CE-05', 'CTR-CE-005');

    $this->getJson('/api/dashboard/contratos-por-estado')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.'.ContractStatus::ACTIVO->value, 2)
        ->assertJsonPath('data.'.ContractStatus::PREVENTA_INACTIVA->value, 1)
        ->assertJsonPath('data.'.ContractStatus::TERMINADO->value, 1)
        ->assertJsonPath('data.'.ContractStatus::RESCINDIDO->value, 1)
        ->assertJsonPath('data.'.ContractStatus::VENCIDO->value, 0);
});

it('permite a socio_gerencia consultar contratos por estado y niega a admin_sistema', function () {
    createContractWithStatus(ContractStatus::ACTIVO->value, 'L-CE-10', 'CTR-CE-010');

    $this->actingAsRole(RoleName::SOCIO_GERENCIA->value);

    $this->getJson('/api/dashboard/contratos-por-estado')
        ->assertOk()
        ->assertJsonPath('data.'.ContractStatus::ACTIVO->value, 1);

    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->getJson('/api/dashboard/contratos-por-estado')->assertForbidden();
});
