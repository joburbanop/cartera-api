<?php

use App\Enums\RoleName;
use App\Models\AmortizationInstallment;
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
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $this->projectA = Project::query()->create([
        'name' => 'Proyecto Contrato A',
        'description' => 'A',
        'location' => 'Cali',
        'status' => 'active',
    ]);
    $this->projectB = Project::query()->create([
        'name' => 'Proyecto Contrato B',
        'description' => 'B',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
});

function contractIdsFromIndex(array $payload): array
{
    return collect($payload['data']['data'] ?? $payload['data'] ?? [])
        ->pluck('id')
        ->all();
}

function loggedSql(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $sqls = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return $sqls;
}

function assertSqlUsesLotsNumberNotContractLotNumber(array $sqls): void
{
    $blob = implode("\n", $sqls);

    expect($blob)->toContain('lots')
        ->and($blob)->toContain('number')
        ->and($blob)->not->toContain('lot_number');
}

function createContractOverdueInstallment(Contract $contract): AmortizationInstallment
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

it('filtra por número de contrato exacto o like', function () {
    $target = Contract::factory()->create(['contract_number' => 'SM-FILTRO-001']);
    Contract::factory()->create(['contract_number' => 'CTR-OTRO']);

    $exact = $this->getJson('/api/contracts?contract_number=SM-FILTRO-001&per_page=50')->assertOk()->json();
    expect($exact['data']['total'])->toBe(1)
        ->and(contractIdsFromIndex($exact))->toBe([$target->id]);

    $like = $this->getJson('/api/contracts?contract_number=FILTRO&per_page=50')->assertOk()->json();
    expect($like['data']['total'])->toBe(1)
        ->and(contractIdsFromIndex($like))->toBe([$target->id]);
});

it('filtra por titular nombre o documento', function () {
    $holder = Customer::factory()->create([
        'name' => 'Zeta Contrato Titular',
        'document_number' => '44556677',
    ]);
    $other = Customer::factory()->create(['name' => 'Otro Cliente']);
    $target = Contract::factory()->create(['customer_id' => $holder->id]);
    Contract::factory()->create(['customer_id' => $other->id]);

    $sqls = loggedSql(function () use (&$byName, &$byDoc) {
        $byName = $this->getJson('/api/contracts?customer=Zeta%20Contrato&per_page=50')->assertOk()->json();
        $byDoc = $this->getJson('/api/contracts?customer=44556677&per_page=50')->assertOk()->json();
    });

    expect($byName['data']['total'])->toBe(1)
        ->and(contractIdsFromIndex($byName))->toBe([$target->id])
        ->and(contractIdsFromIndex($byDoc))->toBe([$target->id]);

    expect(implode("\n", $sqls))->toContain('customers');
});

it('filtra por proyecto a través de lots.project_id', function () {
    $lotA = Lot::factory()->create(['project_id' => $this->projectA->id, 'number' => 'L-88']);
    $lotB = Lot::factory()->create(['project_id' => $this->projectB->id, 'number' => 'L-99']);
    $inA = Contract::factory()->create(['lot_id' => $lotA->id]);
    Contract::factory()->create(['lot_id' => $lotB->id]);

    $sqls = loggedSql(function () use (&$byProject) {
        $byProject = $this->getJson('/api/contracts?project_id='.$this->projectA->id.'&per_page=50')
            ->assertOk()
            ->json();
    });

    expect($byProject['data']['total'])->toBe(1)
        ->and(contractIdsFromIndex($byProject))->toBe([$inA->id]);

    $blob = implode("\n", $sqls);
    expect($blob)->toContain('lots')
        ->and($blob)->toContain('project_id');
});

it('filtra por número de lote consultando lots.number, no contracts.lot_number', function () {
    $lotA = Lot::factory()->create(['project_id' => $this->projectA->id, 'number' => 'L-88']);
    $lotB = Lot::factory()->create(['project_id' => $this->projectB->id, 'number' => 'L-99']);
    $inA = Contract::factory()->create(['lot_id' => $lotA->id]);
    Contract::factory()->create(['lot_id' => $lotB->id]);

    $sqls = loggedSql(function () use (&$byLot) {
        $byLot = $this->getJson('/api/contracts?lot_number=88&per_page=50')
            ->assertOk()
            ->json();
    });

    expect($byLot['data']['total'])->toBe(1)
        ->and(contractIdsFromIndex($byLot))->toBe([$inA->id]);

    assertSqlUsesLotsNumberNotContractLotNumber($sqls);
});

it('filtra por estado del contrato', function () {
    $activo = Contract::factory()->create(['status' => 'activo']);
    Contract::factory()->create(['status' => 'preventa_inactiva']);

    $payload = $this->getJson('/api/contracts?status=activo&per_page=50')->assertOk()->json();
    expect($payload['data']['total'])->toBe(1)
        ->and(contractIdsFromIndex($payload))->toBe([$activo->id]);
});

it('filtra cartera al día y con mora', function () {
    $mora = Contract::factory()->create();
    $ok = Contract::factory()->create();

    createContractOverdueInstallment($mora);
    AmortizationInstallment::query()->create([
        'contract_id' => $ok->id,
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

    $sqls = loggedSql(function () use (&$moraPayload, &$okPayload) {
        $moraPayload = $this->getJson('/api/contracts?cartera=mora&per_page=50')->assertOk()->json();
        $okPayload = $this->getJson('/api/contracts?cartera=al_dia&per_page=50')->assertOk()->json();
    });

    expect(contractIdsFromIndex($moraPayload))->toBe([$mora->id])
        ->and(contractIdsFromIndex($okPayload))->toBe([$ok->id]);

    $blob = implode("\n", $sqls);
    expect($blob)->toContain('amortization_installments');
});

it('filtra por rango de fecha de firma', function () {
    $inside = Contract::factory()->create(['start_date' => '2026-02-15']);
    Contract::factory()->create(['start_date' => '2025-12-01']);
    Contract::factory()->create(['start_date' => '2026-04-10']);

    $payload = $this->getJson('/api/contracts?start_date_from=2026-01-01&start_date_to=2026-03-31&per_page=50')
        ->assertOk()
        ->json();

    expect($payload['data']['total'])->toBe(1)
        ->and(contractIdsFromIndex($payload))->toBe([$inside->id]);
});

it('combina filtros con AND y conserva lot_id', function () {
    $lot = Lot::factory()->create([
        'project_id' => $this->projectA->id,
        'number' => 'L-77',
    ]);
    $customer = Customer::factory()->create(['name' => 'Ana Combinada']);
    $match = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'contract_number' => 'SM-COMB-1',
        'status' => 'activo',
        'start_date' => '2026-02-01',
    ]);
    Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'contract_number' => 'SM-COMB-2',
        'status' => 'preventa_inactiva',
        'start_date' => '2026-02-01',
    ]);

    $payload = $this->getJson(
        '/api/contracts?lot_id='.$lot->id.'&status=activo&customer=Ana%20Combinada&per_page=50'
    )->assertOk()->json();

    expect($payload['data']['total'])->toBe(1)
        ->and(contractIdsFromIndex($payload))->toBe([$match->id]);
});
