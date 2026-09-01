<?php

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $project = Project::query()->create([
        'name' => 'Proyecto Recaudo Mensual',
        'description' => 'Fixture recaudo mensual',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => 'L-RM-01',
    ]);

    $this->contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'contract_number' => 'CTR-RM-001',
        'status' => 'activo',
    ]);
});

function createMonthlyDashboardPayment(array $overrides = []): Transaction
{
    return Transaction::query()->create(array_merge([
        'contract_id' => test()->contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT->value,
        'amount' => '10000.00',
        'transaction_date' => now()->toDateString(),
        'payment_method' => PaymentMethod::TRANSFER->value,
    ], $overrides));
}

it('agrupa recaudo de 12 meses por transaction_date e incluye meses en cero', function () {
    $this->travelTo(Carbon::parse('2026-09-15'));
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    createMonthlyDashboardPayment([
        'amount' => '40000.00',
        'transaction_date' => '2026-09-10',
    ]);

    createMonthlyDashboardPayment([
        'amount' => '15000.00',
        'transaction_date' => '2026-07-20',
    ]);

    createMonthlyDashboardPayment([
        'amount' => '99999.00',
        'transaction_date' => '2025-08-01',
    ]);

    $response = $this->getJson('/api/dashboard/recaudo-mensual')
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $series = $response->json('data');

    expect($series)->toHaveCount(12);
    expect($series[0]['mes'])->toBe('2025-10');
    expect($series[11]['mes'])->toBe('2026-09');
    expect($series[11]['total'])->toBe('40000.00');
    expect($series[9]['mes'])->toBe('2026-07');
    expect($series[9]['total'])->toBe('15000.00');
    expect($series[1]['total'])->toBe('0.00');
});

it('permite a socio_gerencia consultar el recaudo mensual y niega a admin_sistema', function () {
    $this->actingAsRole(RoleName::SOCIO_GERENCIA->value);

    $this->getJson('/api/dashboard/recaudo-mensual')
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->getJson('/api/dashboard/recaudo-mensual')->assertForbidden();
});
