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

    $this->project = Project::query()->create([
        'name' => 'Proyecto Actividad',
        'description' => 'Fixture actividad',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $this->customer = Customer::factory()->create([
        'name' => 'Cliente Actividad',
    ]);
});

it('ordena la actividad de más reciente a más antigua y limita a 10 ítems', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $baseContract = Contract::factory()->create([
        'customer_id' => $this->customer->id,
        'lot_id' => Lot::factory()->create([
            'project_id' => $this->project->id,
            'number' => 'L-ACT-BASE',
        ])->id,
        'contract_number' => 'CTR-ACT-BASE',
        'start_date' => now()->subDays(20)->toDateString(),
        'status' => 'activo',
    ]);

    foreach (range(1, 8) as $offset) {
        Transaction::query()->create([
            'contract_id' => $baseContract->id,
            'transaction_type' => TransactionType::REGULAR_PAYMENT->value,
            'amount' => '1000.00',
            'transaction_date' => now()->subDays($offset)->toDateString(),
            'payment_method' => PaymentMethod::TRANSFER->value,
        ]);
    }

    foreach (range(1, 4) as $index) {
        Contract::factory()->create([
            'customer_id' => $this->customer->id,
            'lot_id' => Lot::factory()->create([
                'project_id' => $this->project->id,
                'number' => 'L-ACT-'.$index,
            ])->id,
            'contract_number' => 'CTR-ACT-NEW-'.$index,
            'start_date' => now()->subDays($index - 1)->toDateString(),
            'sale_price' => '200000.00',
            'status' => 'activo',
        ]);
    }

    $items = $this->getJson('/api/dashboard/actividad-reciente')
        ->assertOk()
        ->json('data');

    expect($items)->toHaveCount(10)
        ->and($items[0]['fecha'])->toBe(now()->toDateString())
        ->and($items[0]['tipo'])->toBe('contrato');

    $fechas = array_column($items, 'fecha');
    $sorted = $fechas;
    rsort($sorted);

    expect($fechas)->toBe($sorted);
});

it('niega la actividad reciente a admin_sistema', function () {
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->getJson('/api/dashboard/actividad-reciente')->assertForbidden();
});
