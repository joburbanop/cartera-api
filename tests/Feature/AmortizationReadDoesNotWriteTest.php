<?php

use App\Enums\RoleName;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Financial\Amortization\AmortizationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function contractWithoutPlan(): Contract
{
    test()->seed(RolesAndPermissionsSeeder::class);

    $project = Project::query()->create([
        'name' => 'Proyecto sin plan',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create(['project_id' => $project->id]);

    $contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'status' => 'activo',
    ]);
    $contract->installments()->delete();

    return $contract->fresh();
}

it('el GET de amortización no persiste un plan vacío', function () {
    $contract = contractWithoutPlan();
    $this->actingAsRole(RoleName::SOCIO_GERENCIA->value);

    $this->mock(AmortizationService::class, function ($mock) {
        $mock->shouldReceive('generateInitialProjection')->never();
    });

    $this->getJson("/api/contracts/{$contract->id}/amortization")
        ->assertOk()
        ->assertJsonPath('data', []);

    expect(AmortizationInstallment::query()->where('contract_id', $contract->id)->count())->toBe(0);
});

it('el PDF sin plan responde 404 y no genera filas', function () {
    $contract = contractWithoutPlan();
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $this->getJson("/api/contracts/{$contract->id}/download-pdf")
        ->assertNotFound()
        ->assertJsonPath('message', 'Este contrato no tiene un plan de amortización generado.');

    expect(AmortizationInstallment::query()->where('contract_id', $contract->id)->count())->toBe(0);
});

it('el socio no puede generar el plan por POST', function () {
    $contract = contractWithoutPlan();
    $this->actingAsRole(RoleName::SOCIO_GERENCIA->value);

    $this->postJson("/api/contracts/{$contract->id}/generate-amortization")
        ->assertForbidden();

    expect(AmortizationInstallment::query()->where('contract_id', $contract->id)->count())->toBe(0);
});
