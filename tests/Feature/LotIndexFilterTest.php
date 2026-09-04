<?php

use App\Enums\LotStatus;
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
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $this->projectA = Project::query()->create([
        'name' => 'Proyecto Filtro A',
        'description' => 'A',
        'location' => 'Cali',
        'status' => 'active',
    ]);
    $this->projectB = Project::query()->create([
        'name' => 'Proyecto Filtro B',
        'description' => 'B',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
});

function lotIdsFromIndex(array $payload): array
{
    return collect($payload['data']['data'] ?? $payload['data'] ?? [])
        ->pluck('id')
        ->all();
}

function createOverdueInstallment(Contract $contract): AmortizationInstallment
{
    return AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 1,
        'due_date' => now()->subDay()->toDateString(),
        'installment_value' => '1000000.00',
        'extra_payment' => '0.00',
        'interest_value' => '0.00',
        'principal_value' => '1000000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000000.00',
        'remaining_balance' => '5000000.00',
        'projected_balance' => '5000000.00',
        'status' => 'pending',
    ]);
}

it('filtra por número de lote exacto o like', function () {
    $target = Lot::factory()->create(['project_id' => $this->projectA->id, 'number' => 'PRUEBA-FECHAS-001']);
    Lot::factory()->create(['project_id' => $this->projectA->id, 'number' => 'L-04']);

    $exact = $this->getJson('/api/lots?number=PRUEBA-FECHAS-001&per_page=50')->assertOk()->json();
    expect($exact['data']['total'])->toBe(1)
        ->and(lotIdsFromIndex($exact))->toBe([$target->id]);

    $like = $this->getJson('/api/lots?number=FECHAS&per_page=50')->assertOk()->json();
    expect($like['data']['total'])->toBe(1)
        ->and(lotIdsFromIndex($like))->toBe([$target->id]);
});

it('filtra por estado del lote', function () {
    $separado = Lot::factory()->create([
        'project_id' => $this->projectA->id,
        'status' => LotStatus::SEPARADO->value,
    ]);
    Lot::factory()->create([
        'project_id' => $this->projectA->id,
        'status' => LotStatus::DISPONIBLE->value,
    ]);

    $payload = $this->getJson('/api/lots?status=separado&per_page=50')->assertOk()->json();
    expect($payload['data']['total'])->toBe(1)
        ->and(lotIdsFromIndex($payload))->toBe([$separado->id]);
});

it('filtra por proyecto', function () {
    $inA = Lot::factory()->create(['project_id' => $this->projectA->id]);
    Lot::factory()->create(['project_id' => $this->projectB->id]);

    $payload = $this->getJson('/api/lots?project_id='.$this->projectA->id.'&per_page=50')->assertOk()->json();
    expect($payload['data']['total'])->toBe(1)
        ->and(lotIdsFromIndex($payload))->toBe([$inA->id]);
});

it('filtra por tipo de plan incluyendo sin plan', function () {
    $customer = Customer::factory()->create();

    $without = Lot::factory()->create(['project_id' => $this->projectA->id, 'number' => 'SIN-PLAN']);
    $standardLot = Lot::factory()->create(['project_id' => $this->projectA->id, 'number' => 'STD']);
    $specialLot = Lot::factory()->create(['project_id' => $this->projectA->id, 'number' => 'ESP']);
    $customLot = Lot::factory()->create(['project_id' => $this->projectA->id, 'number' => 'CUST']);

    Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $standardLot->id,
        'is_special_lot' => false,
        'is_custom_plan' => false,
    ]);
    Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $specialLot->id,
        'is_special_lot' => true,
        'is_custom_plan' => false,
    ]);
    Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $customLot->id,
        'is_special_lot' => false,
        'is_custom_plan' => true,
    ]);

    expect(lotIdsFromIndex($this->getJson('/api/lots?plan_type=none&per_page=50')->json()))->toBe([$without->id])
        ->and(lotIdsFromIndex($this->getJson('/api/lots?plan_type=standard&per_page=50')->json()))->toBe([$standardLot->id])
        ->and(lotIdsFromIndex($this->getJson('/api/lots?plan_type=special&per_page=50')->json()))->toBe([$specialLot->id])
        ->and(lotIdsFromIndex($this->getJson('/api/lots?plan_type=custom&per_page=50')->json()))->toBe([$customLot->id]);
});

it('filtra cartera al día y con mora', function () {
    $customer = Customer::factory()->create();
    $moraLot = Lot::factory()->create(['project_id' => $this->projectA->id, 'number' => 'MORA']);
    $okLot = Lot::factory()->create(['project_id' => $this->projectA->id, 'number' => 'ALDIA']);
    Lot::factory()->create(['project_id' => $this->projectA->id, 'number' => 'SIN']);

    $moraContract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $moraLot->id,
    ]);
    $okContract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $okLot->id,
    ]);

    createOverdueInstallment($moraContract);
    AmortizationInstallment::query()->create([
        'contract_id' => $okContract->id,
        'installment_number' => 1,
        'due_date' => now()->addMonth()->toDateString(),
        'installment_value' => '1000000.00',
        'extra_payment' => '0.00',
        'interest_value' => '0.00',
        'principal_value' => '1000000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000000.00',
        'remaining_balance' => '5000000.00',
        'projected_balance' => '5000000.00',
        'status' => 'pending',
    ]);

    expect(lotIdsFromIndex($this->getJson('/api/lots?cartera=mora&per_page=50')->json()))->toBe([$moraLot->id])
        ->and(lotIdsFromIndex($this->getJson('/api/lots?cartera=al_dia&per_page=50')->json()))->toBe([$okLot->id]);
});

it('filtra por titular nombre o documento', function () {
    $holder = Customer::factory()->create([
        'name' => 'Zeta Filtro Titular',
        'document_number' => '44556677',
    ]);
    $other = Customer::factory()->create(['name' => 'Otro Cliente']);
    $lot = Lot::factory()->create(['project_id' => $this->projectA->id]);
    Lot::factory()->create(['project_id' => $this->projectA->id]);

    Contract::factory()->create([
        'customer_id' => $holder->id,
        'lot_id' => $lot->id,
    ]);
    Contract::factory()->create([
        'customer_id' => $other->id,
        'lot_id' => Lot::factory()->create(['project_id' => $this->projectA->id])->id,
    ]);

    $byName = $this->getJson('/api/lots?customer=Zeta%20Filtro&per_page=50')->assertOk()->json();
    $byDoc = $this->getJson('/api/lots?customer=44556677&per_page=50')->assertOk()->json();

    expect($byName['data']['total'])->toBe(1)
        ->and(lotIdsFromIndex($byName))->toBe([$lot->id])
        ->and(lotIdsFromIndex($byDoc))->toBe([$lot->id]);
});

it('combina filtros con AND y expone total para el contador', function () {
    $customer = Customer::factory()->create(['name' => 'Ana Combinada', 'document_number' => '99887766']);
    $match = Lot::factory()->create([
        'project_id' => $this->projectA->id,
        'number' => 'L-99',
        'status' => LotStatus::PREVENTA->value,
    ]);
    Lot::factory()->create([
        'project_id' => $this->projectA->id,
        'number' => 'L-98',
        'status' => LotStatus::DISPONIBLE->value,
    ]);
    Lot::factory()->create([
        'project_id' => $this->projectB->id,
        'number' => 'L-99',
        'status' => LotStatus::PREVENTA->value,
    ]);

    Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $match->id,
        'is_special_lot' => false,
        'is_custom_plan' => false,
    ]);

    $payload = $this->getJson(
        '/api/lots?number=L-99&status=preventa&project_id='.$this->projectA->id.'&plan_type=standard&customer=Ana%20Combinada&per_page=50'
    )->assertOk()->json();

    expect($payload['data']['total'])->toBe(1)
        ->and(lotIdsFromIndex($payload))->toBe([$match->id]);
});

it('rechaza reservado como estado de alta', function () {
    $this->postJson('/api/lots', [
        'project_id' => $this->projectA->id,
        'number' => 'L-RESERVADO',
        'area_m2' => 100,
        'price_m2' => 1000000,
        'list_price' => 100000000,
        'status' => 'reservado',
    ])->assertUnprocessable();
});
