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
        'name' => 'Proyecto Resumen Mora',
        'description' => 'Fixture cartera vencida resumen',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => 'L-CVR-01',
    ]);

    $this->contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'contract_number' => 'CTR-CVR-001',
        'status' => 'activo',
    ]);
});

function createResumenInstallment(array $overrides = []): AmortizationInstallment
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

it('cuenta cuotas vencidas con el mismo criterio de cartera-mora y el resto al día', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    createResumenInstallment([
        'installment_number' => 0,
        'due_date' => now()->subMonth()->toDateString(),
        'status' => AmortizationStatus::PENDING->value,
    ]);

    createResumenInstallment([
        'installment_number' => 1,
        'due_date' => now()->subDay()->toDateString(),
        'status' => AmortizationStatus::PENDING->value,
    ]);

    createResumenInstallment([
        'installment_number' => 2,
        'due_date' => now()->addDays(10)->toDateString(),
        'status' => AmortizationStatus::PENDING->value,
    ]);

    createResumenInstallment([
        'installment_number' => 3,
        'due_date' => now()->subDays(5)->toDateString(),
        'status' => AmortizationStatus::PAID->value,
    ]);

    $this->getJson('/api/dashboard/cartera-vencida-resumen')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.vencidas', 1)
        ->assertJsonPath('data.al_dia', 2);

    $this->getJson('/api/dashboard/cartera-mora')
        ->assertOk()
        ->assertJsonPath('data.cantidad_cuotas_vencidas', 1);
});

it('permite a socio_gerencia consultar el resumen y niega a admin_sistema', function () {
    createResumenInstallment([
        'installment_number' => 1,
        'due_date' => now()->subDay()->toDateString(),
        'status' => AmortizationStatus::PENDING->value,
    ]);

    $this->actingAsRole(RoleName::SOCIO_GERENCIA->value);

    $this->getJson('/api/dashboard/cartera-vencida-resumen')
        ->assertOk()
        ->assertJsonPath('data.vencidas', 1);

    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->getJson('/api/dashboard/cartera-vencida-resumen')->assertForbidden();
});
