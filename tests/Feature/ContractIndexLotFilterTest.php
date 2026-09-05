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
});

it('filtra contratos por lot_id aunque el lote no esté en la primera página de latest()', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $project = Project::query()->create([
        'name' => 'Proyecto Filtro Lote',
        'description' => 'Fixture index',
        'location' => 'Cali',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();

    $targetLot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => '1',
        'list_price' => '160779700.00',
        'area_m2' => '120.00',
        'status' => 'preventa',
    ]);

    $oldContract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $targetLot->id,
        'contract_number' => 'SM-LOTE-1',
        'sale_price' => '160779700.00',
        'status' => 'preventa_inactiva',
        'created_at' => now()->subYear(),
        'updated_at' => now()->subYear(),
    ]);
    $oldContract->forceFill([
        'created_at' => now()->subYear(),
        'updated_at' => now()->subYear(),
    ])->save();

    Transaction::query()->create([
        'contract_id' => $oldContract->id,
        'transaction_type' => TransactionType::DOWN_PAYMENT->value,
        'amount' => '10500000.00',
        'transaction_date' => now()->toDateString(),
        'payment_method' => PaymentMethod::CASH->value,
    ]);

    for ($i = 0; $i < 16; $i++) {
        $lot = Lot::factory()->create([
            'project_id' => $project->id,
            'number' => 'N-'.$i,
        ]);
        Contract::factory()->create([
            'customer_id' => $customer->id,
            'lot_id' => $lot->id,
            'contract_number' => 'CTR-NEW-'.$i,
            'created_at' => now()->addMinutes($i + 1),
            'updated_at' => now()->addMinutes($i + 1),
        ]);
    }

    $unfiltered = $this->getJson('/api/contracts')->assertOk();
    $firstPageNumbers = collect($unfiltered->json('data.data'))->pluck('contract_number');
    expect($firstPageNumbers)->not->toContain('SM-LOTE-1');

    $filtered = $this->getJson('/api/contracts?lot_id='.$targetLot->id.'&per_page=100')
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $items = $filtered->json('data.data');
    expect($items)->toHaveCount(1)
        ->and($items[0]['contract_number'])->toBe('SM-LOTE-1')
        ->and($items[0]['lot_id'])->toBe($targetLot->id)
        ->and($items[0]['transactions'])->toHaveCount(1)
        ->and($items[0]['transactions'][0]['amount'])->toBe('10500000.00');
});

it('devuelve el lote por id para la hoja de vida', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $project = Project::query()->create([
        'name' => 'Proyecto Show Lote',
        'description' => 'Fixture',
        'location' => 'Cali',
        'status' => 'active',
    ]);
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => '1',
        'area_m2' => '180.50',
        'list_price' => '90000000.00',
        'status' => 'preventa',
    ]);

    $this->getJson('/api/lots/'.$lot->id)
        ->assertOk()
        ->assertJsonPath('data.id', $lot->id)
        ->assertJsonPath('data.number', '1')
        ->assertJsonPath('data.area_m2', '180.50')
        ->assertJsonPath('data.list_price', '90000000.00');
});

it('incluye el conteo y el contrato único en el listado de lotes', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $project = Project::query()->create([
        'name' => 'Proyecto Conteo Lotes',
        'description' => 'Fixture',
        'location' => 'Cali',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    $withContract = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => '1',
        'status' => 'preventa',
    ]);
    $empty = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => '2',
        'status' => 'disponible',
    ]);
    $contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $withContract->id,
        'contract_number' => 'SM-LOTE-1',
    ]);

    $response = $this->getJson('/api/lots?project_id='.$project->id.'&per_page=50')->assertOk();
    $items = collect($response->json('data.data'));

    $mappedWithContract = $items->firstWhere('id', $withContract->id);
    $mappedEmpty = $items->firstWhere('id', $empty->id);

    expect($mappedWithContract['contracts_count'])->toBe(1)
        ->and($mappedWithContract['contracts'][0]['id'])->toBe($contract->id)
        ->and($mappedEmpty['contracts_count'])->toBe(0)
        ->and($mappedEmpty['contracts'])->toBeArray()
        ->and($mappedEmpty['contracts'])->toHaveCount(0);
});
