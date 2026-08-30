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

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $project = Project::query()->create([
        'name' => 'Proyecto Recaudo',
        'description' => 'Fixture recaudo',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => 'L-REC-01',
    ]);

    $this->contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'contract_number' => 'CTR-REC-001',
        'status' => 'activo',
    ]);
});

function createDashboardPayment(array $overrides = []): Transaction
{
    return Transaction::query()->create(array_merge([
        'contract_id' => test()->contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT->value,
        'amount' => '10000.00',
        'transaction_date' => now()->toDateString(),
        'payment_method' => PaymentMethod::TRANSFER->value,
    ], $overrides));
}

it('suma solo pagos del mes actual y excluye uno de hace dos meses', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    createDashboardPayment([
        'amount' => '25000.00',
        'transaction_date' => now()->subMonths(2)->toDateString(),
    ]);

    createDashboardPayment([
        'amount' => '40000.00',
        'transaction_date' => now()->startOfMonth()->toDateString(),
    ]);

    createDashboardPayment([
        'amount' => '15000.00',
        'transaction_date' => now()->toDateString(),
    ]);

    $this->getJson('/api/dashboard/recaudo-reciente')
        ->assertOk()
        ->assertJsonPath('data.cantidad_pagos', 2)
        ->assertJsonPath('data.total_recaudado', '55000.00');
});

it('niega el recaudo reciente a admin_sistema', function () {
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->getJson('/api/dashboard/recaudo-reciente')->assertForbidden();
});
