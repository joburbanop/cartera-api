<?php

use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Enums\PaymentMethod;


uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function archiveContractFixture(array $overrides = []): array
{
    $user = User::factory()->create();
    $user->assignRole(RoleName::ADMINISTRADOR->value);
    Sanctum::actingAs($user);

    $customer = Customer::factory()->create();

    $project = Project::create([
        'name' => 'Proyecto de prueba',
        'description' => 'Proyecto para pruebas de contratos',
        'location' => 'Cali',
        'status' => 'activo',
    ]);

    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'status' => LotStatus::DISPONIBLE,
    ]);

    $contract = Contract::factory()->create(array_merge([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'status' => ContractStatus::PREVENTA_INACTIVA,
        'sale_price' => '100000000.00',
        'down_payment_pactada' => '20000000.00',
        'term_months' => 60,
        'interest_rate' => '1.00',
        'start_date' => '2026-01-01',
        'initial_payment_date' => '2026-01-01',
        'first_installment_date' => '2026-02-01',
        'regular_payment_start_date' => '2026-02-01',
        'preventa_installments_count' => 1,
        'is_custom_plan' => false,
        'is_special_lot' => false,
    ], $overrides));

    $lot->update([
        'status' => LotStatus::PREVENTA,
    ]);

    return compact(
        'user',
        'project',
        'customer',
        'lot',
        'contract'
    );
}

it('permite archivar una preventa inactiva sin actividad financiera y libera el lote', function () {
    $fixture = archiveContractFixture();

    $response = $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/archive"
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();

    expect($fixture['contract']->trashed())
        ->toBeTrue()
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::DISPONIBLE);
});

it('rechaza archivar una preventa inactiva que tiene actividad financiera', function () {
    $fixture = archiveContractFixture();

    Transaction::create([
        'contract_id' => $fixture['contract']->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT->value,
        'amount' => 500000,
        'transaction_date' => '2026-02-01',
        'payment_method' => PaymentMethod::TRANSFER->value,
        'notes' => 'Pago de prueba',
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/archive"
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract');

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();

    expect($fixture['contract']->trashed())
        ->toBeFalse()
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::PREVENTA);
});

it('rechaza archivar un contrato activo', function () {
    $fixture = archiveContractFixture([
        'status' => ContractStatus::ACTIVO,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/archive"
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract');

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();

    expect($fixture['contract']->trashed())
        ->toBeFalse()
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::PREVENTA);
});
it('permite archivar un contrato terminado sin modificar el lote', function () {
    $fixture = archiveContractFixture([
        'status' => ContractStatus::TERMINADO,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/archive"
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();

    expect($fixture['contract']->trashed())
        ->toBeTrue()
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::PREVENTA);
});
it('permite archivar un contrato rescindido y libera el lote', function () {
    $fixture = archiveContractFixture([
        'status' => ContractStatus::RESCINDIDO,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/archive"
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();

    expect($fixture['contract']->trashed())
        ->toBeTrue()
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::DISPONIBLE);
});
it('rechaza archivar una preventa inactiva que tiene un reembolso', function () {
    $fixture = archiveContractFixture();

    Transaction::create([
        'contract_id' => $fixture['contract']->id,
        'transaction_type' => TransactionType::REFUND->value,
        'amount' => 500000,
        'transaction_date' => '2026-02-01',
        'payment_method' => PaymentMethod::TRANSFER->value,
        'notes' => 'Reembolso de prueba',
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/archive"
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract');

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();

    expect($fixture['contract']->trashed())
        ->toBeFalse()
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::PREVENTA);
});

it('permite restaurar una preventa inactiva archivada y devuelve el lote a preventa', function () {
    $fixture = archiveContractFixture();

    $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/archive"
        )
        ->assertSuccessful();

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();

    expect($fixture['contract']->trashed())
        ->toBeTrue()
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::DISPONIBLE);

    $response = $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/restore"
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();

    expect($fixture['contract']->trashed())
        ->toBeFalse()
        ->and($fixture['contract']->deleted_by)
        ->toBeNull()
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::PREVENTA);
});


it('rechaza restaurar un contrato rescindido archivado', function () {
    $fixture = archiveContractFixture([
        'status' => ContractStatus::RESCINDIDO,
    ]);

    $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/archive"
        )
        ->assertSuccessful();

    $response = $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/restore"
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract');

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();

    expect($fixture['contract']->trashed())
        ->toBeTrue()
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::DISPONIBLE);
});

it('permite restaurar un contrato terminado archivado sin modificar el lote', function () {
    $fixture = archiveContractFixture([
        'status' => ContractStatus::TERMINADO,
    ]);

    $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/archive"
        )
        ->assertSuccessful();

    $fixture['lot']->refresh();

    expect($fixture['lot']->status)
        ->toBe(LotStatus::PREVENTA);

    $response = $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/restore"
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();

    expect($fixture['contract']->trashed())
        ->toBeFalse()
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::PREVENTA);
});

it('rechaza restaurar una preventa si su lote ya no está disponible', function () {
    $fixture = archiveContractFixture();

    $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/archive"
        )
        ->assertSuccessful();

    $fixture['lot']->refresh();

    expect($fixture['lot']->status)
        ->toBe(LotStatus::DISPONIBLE);

    $fixture['lot']->update([
        'status' => LotStatus::PREVENTA,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/restore"
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('lot_id');

    $fixture['contract']->refresh();

    expect($fixture['contract']->trashed())
        ->toBeTrue();
});

it('rechaza restaurar un contrato que no está archivado', function () {
    $fixture = archiveContractFixture([
        'status' => ContractStatus::ACTIVO,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->patchJson(
            "/api/contracts/{$fixture['contract']->id}/restore"
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract');

    $fixture['contract']->refresh();

    expect($fixture['contract']->trashed())
        ->toBeFalse();
});