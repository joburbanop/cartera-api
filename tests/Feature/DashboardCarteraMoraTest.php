<?php

use App\Enums\AmortizationStatus;
use App\Enums\RoleName;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $project = Project::query()->create([
        'name' => 'Proyecto Mora',
        'description' => 'Fixture dashboard mora',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => 'L-MORA-01',
    ]);

    $this->contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'contract_number' => 'CTR-MORA-001',
        'status' => 'activo',
    ]);
});

function createInstallment(array $overrides = []): AmortizationInstallment
{
    return AmortizationInstallment::query()->create(array_merge([
        'contract_id' => test()->contract->id,
        'installment_number' => 1,
        'due_date' => now()->subDay()->toDateString(),
        'installment_value' => '100000.00',
        'extra_payment' => '0.00',
        'interest_value' => '10000.00',
        'principal_value' => '90000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '0.00',
        'remaining_balance' => '900000.00',
        'projected_balance' => '900000.00',
        'status' => AmortizationStatus::PENDING->value,
    ], $overrides));
}

it('cuenta una cuota pending vencida sin pagos, que una query por overdue se saltaría', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    createInstallment([
        'installment_number' => 0,
        'due_date' => now()->subMonth()->toDateString(),
        'installment_value' => '500000.00',
        'status' => AmortizationStatus::PENDING->value,
    ]);

    createInstallment([
        'installment_number' => 1,
        'due_date' => now()->subDay()->toDateString(),
        'installment_value' => '85000.00',
        'quota_debt' => '0.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'status' => AmortizationStatus::PENDING->value,
    ]);

    $this->getJson('/api/dashboard/cartera-mora')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.cantidad_cuotas_vencidas', 1)
        ->assertJsonPath('data.total_vencido', '85000.00');
});

it('usa quota_debt y no el installment_value completo en una cuota parcial vencida', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    createInstallment([
        'installment_number' => 2,
        'due_date' => now()->subDays(10)->toDateString(),
        'installment_value' => '100000.00',
        'quota_debt' => '40000.00',
        'interest_paid' => '10000.00',
        'principal_paid' => '50000.00',
        'status' => AmortizationStatus::PARTIAL->value,
    ]);

    $this->getJson('/api/dashboard/cartera-mora')
        ->assertOk()
        ->assertJsonPath('data.cantidad_cuotas_vencidas', 1)
        ->assertJsonPath('data.total_vencido', '40000.00');
});

it('permite a socio_gerencia consultar la cartera en mora y niega a admin_sistema', function () {
    createInstallment([
        'installment_number' => 1,
        'due_date' => now()->subDay()->toDateString(),
        'installment_value' => '12000.00',
        'status' => AmortizationStatus::PENDING->value,
    ]);

    $this->actingAsRole(RoleName::SOCIO_GERENCIA->value);

    $this->getJson('/api/dashboard/cartera-mora')
        ->assertOk()
        ->assertJsonPath('data.cantidad_cuotas_vencidas', 1)
        ->assertJsonPath('data.total_vencido', '12000.00');

    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->getJson('/api/dashboard/cartera-mora')->assertForbidden();
});
