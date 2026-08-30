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
        'name' => 'Proyecto Vencimientos',
        'description' => 'Fixture vencimientos',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => 'L-VENC-01',
    ]);

    $this->contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'contract_number' => 'CTR-VENC-001',
        'status' => 'activo',
    ]);
});

function createUpcomingInstallment(array $overrides = []): AmortizationInstallment
{
    return AmortizationInstallment::query()->create(array_merge([
        'contract_id' => test()->contract->id,
        'installment_number' => 1,
        'due_date' => now()->addDays(3)->toDateString(),
        'installment_value' => '80000.00',
        'extra_payment' => '0.00',
        'interest_value' => '8000.00',
        'principal_value' => '72000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '0.00',
        'remaining_balance' => '720000.00',
        'projected_balance' => '720000.00',
        'status' => AmortizationStatus::PENDING->value,
    ], $overrides));
}

it('cuenta la cuota que vence en 3 días y excluye ayer y la de 10 días', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    createUpcomingInstallment([
        'installment_number' => 1,
        'due_date' => now()->subDay()->toDateString(),
        'installment_value' => '50000.00',
    ]);

    createUpcomingInstallment([
        'installment_number' => 2,
        'due_date' => now()->addDays(3)->toDateString(),
        'installment_value' => '80000.00',
    ]);

    createUpcomingInstallment([
        'installment_number' => 3,
        'due_date' => now()->addDays(10)->toDateString(),
        'installment_value' => '90000.00',
    ]);

    $this->getJson('/api/dashboard/proximos-vencimientos')
        ->assertOk()
        ->assertJsonPath('data.cantidad_cuotas', 1)
        ->assertJsonPath('data.total_por_vencer', '80000.00');
});

it('niega los próximos vencimientos a admin_sistema', function () {
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->getJson('/api/dashboard/proximos-vencimientos')->assertForbidden();
});
