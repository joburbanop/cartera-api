<?php

use App\Enums\ContractCustomerRole;
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
});

it('migra el customer_id existente como titular principal en el pivote', function () {
    $customer = Customer::factory()->create(['name' => 'Titular Legacy']);
    $project = Project::query()->create([
        'name' => 'Proyecto Legacy Titular',
        'description' => 'Backfill',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => 'L-LEGACY-T',
    ]);
    $contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'contract_number' => 'CTR-LEGACY-TITULAR',
    ]);

    $row = DB::table('contract_customer')
        ->where('contract_id', $contract->id)
        ->where('customer_id', $customer->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->role)->toBe(ContractCustomerRole::TITULAR_PRINCIPAL->value)
        ->and($contract->fresh()->customer_id)->toBe($customer->id)
        ->and($contract->fresh()->primaryCustomer()?->id)->toBe($customer->id);
});

it('crea un contrato con co-titular y lo expone en el detalle', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $anchor = Customer::factory()->create(['name' => 'Titular Ana QA']);
    $otherHolder = Customer::factory()->create([
        'name' => 'Titular Luis QA',
        'document_number' => '88007766',
    ]);

    $project = Project::query()->create([
        'name' => 'Proyecto Cotitulares',
        'description' => 'Fixture N:M',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => 'L-CO-01',
        'status' => 'disponible',
    ]);

    $this->postJson('/api/contracts', [
        'contract_number' => 'PROM-CO-001',
        'customer_id' => $anchor->id,
        'co_titular_ids' => [$otherHolder->id],
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor QA',
        'sale_price' => 7000000,
        'down_payment_pactada' => 2000000,
        'term_months' => 5,
        'interest_rate' => 0,
        'start_date' => now()->toDateString(),
        'initial_payment_date' => now()->toDateString(),
        'first_installment_date' => now()->addMonth()->toDateString(),
        'regular_payment_start_date' => now()->addMonth()->toDateString(),
        'preventa_installments_count' => 0,
    ])->assertCreated();

    $contract = Contract::query()->where('contract_number', 'PROM-CO-001')->firstOrFail();

    expect($contract->customer_id)->toBe($anchor->id)
        ->and($contract->customers()->count())->toBe(2);

    $this->getJson("/api/contracts/{$contract->id}")
        ->assertOk()
        ->assertJsonPath('data.customer_id', $anchor->id);

    $holders = collect($this->getJson("/api/contracts/{$contract->id}")->json('data.customers'));
    $detail = $this->getJson("/api/contracts/{$contract->id}")->json('data');

    expect($holders->pluck('name')->all())->toContain('Titular Ana QA', 'Titular Luis QA')
        ->and($holders->every(fn ($holder) => ! array_key_exists('pivot', $holder)))->toBeTrue()
        ->and(json_encode($detail))->not->toContain('titular_principal')
        ->and(json_encode($detail))->not->toContain('co_titular')
        ->and(json_encode($detail))->not->toContain('Titular principal')
        ->and(json_encode($detail))->not->toContain('Co-titular');

    $this->getJson("/api/customers/{$otherHolder->id}")
        ->assertOk()
        ->assertJsonPath('data.contracts.0.contract_number', 'PROM-CO-001');
});
