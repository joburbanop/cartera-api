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

it('con plazo de 6 meses guarda tasa 0 y cuota igual a capital/meses', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $customer = Customer::factory()->create();
    $project = Project::query()->create([
        'name' => 'Proyecto plazo corto',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'status' => 'disponible',
    ]);

    $this->postJson('/api/contracts', [
        'contract_number' => 'PROM-CORTA-6',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'sale_price' => 8000000,
        'down_payment_pactada' => 2000000,
        'term_months' => 6,
        'interest_rate' => 1.00,
        'start_date' => now()->toDateString(),
        'initial_payment_date' => now()->toDateString(),
        'first_installment_date' => now()->addMonth()->toDateString(),
        'preventa_installments_count' => 0,
    ])->assertCreated();

    $contract = Contract::query()->where('contract_number', 'PROM-CORTA-6')->firstOrFail();
    $quota = $contract->amortizationInstallments()
        ->where('installment_number', 1)
        ->firstOrFail();

    expect((float) $contract->interest_rate)->toBe(0.0)
        ->and($quota->installment_value)->toBe('1000000.00')
        ->and($quota->interest_value)->toBe('0.00');
});
