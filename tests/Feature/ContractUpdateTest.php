<?php

use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Models\Contract;
use App\Models\ContractPaymentPromise;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Enums\RoleName;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use App\Enums\TransactionType;
use App\Enums\PaymentMethod;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function updateContractPayload(Contract $contract, array $overrides = []): array
{
    return array_merge([
        'contract_number' => $contract->contract_number,
        'customer_id' => $contract->customer_id,
        'lot_id' => $contract->lot_id,
        'seller_name' => $contract->seller_name,
        'sale_price' => $contract->sale_price,
        'down_payment_pactada' => $contract->down_payment_pactada,
        'term_months' => $contract->term_months,
        'interest_rate' => $contract->interest_rate,
        'start_date' => $contract->start_date->toDateString(),
        'initial_payment_date' => $contract->initial_payment_date->toDateString(),
        'first_installment_date' => $contract->first_installment_date->toDateString(),
        'regular_payment_start_date' => $contract->regular_payment_start_date->toDateString(),
        'preventa_installments_count' => $contract->preventa_installments_count,
        'is_custom_plan' => $contract->is_custom_plan,
        'is_special_lot' => $contract->is_special_lot,
        'co_titular_ids' => [],
    ], $overrides);
}

function updateContractFixture(array $overrides = []): array
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

    return compact('user', 'project','customer', 'lot', 'contract');
}
it('actualiza los campos administrativos de un contrato sin actividad financiera', function () {
    $fixture = updateContractFixture();

    $newCustomer = Customer::factory()->create();

    $payload = updateContractPayload($fixture['contract'], [
        'contract_number' => 'CTR-UPDATED-001',
        'seller_name' => 'Nuevo vendedor',
        'customer_id' => $newCustomer->id,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();

    expect($fixture['contract']->contract_number)
        ->toBe('CTR-UPDATED-001')
        ->and($fixture['contract']->seller_name)
        ->toBe('Nuevo vendedor')
        ->and($fixture['contract']->customer_id)
        ->toBe($newCustomer->id);
});

it('actualiza las condiciones financieras de un contrato sin actividad financiera', function () {
    $fixture = updateContractFixture();

    $payload = updateContractPayload($fixture['contract'], [
        'sale_price' => 120000000,
        'down_payment_pactada' => 25000000,
        'term_months' => 48,
        'interest_rate' => 1.25,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();

    expect((float) $fixture['contract']->sale_price)
        ->toBe(120000000.0)
        ->and((float) $fixture['contract']->down_payment_pactada)
        ->toBe(25000000.0)
        ->and((int) $fixture['contract']->term_months)
        ->toBe(48)
        ->and((float) $fixture['contract']->interest_rate)
        ->toBe(1.25);
});

it('rechaza cambios financieros cuando el contrato ya tiene actividad financiera', function () {
    $fixture = updateContractFixture();

    Transaction::create([
    'contract_id' => $fixture['contract']->id,
    'transaction_type' => TransactionType::REGULAR_PAYMENT->value,
    'amount' => 500000,
    'transaction_date' => '2026-02-01',
    'payment_method' => PaymentMethod::TRANSFER->value,
    'notes' => 'Pago de prueba',
]);

    $payload = updateContractPayload($fixture['contract'], [
        'sale_price' => 120000000,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract');

    $fixture['contract']->refresh();

    expect((float) $fixture['contract']->sale_price)
        ->toBe(100000000.0);
});

it('permite cambiar el lote de un contrato sin actividad financiera', function () {
    $fixture = updateContractFixture();

    $newLot = Lot::factory()->create([
        'project_id' => $fixture['project']->id,
        'status' => LotStatus::DISPONIBLE,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'lot_id' => $newLot->id,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();
    $newLot->refresh();

    expect($fixture['contract']->lot_id)
        ->toBe($newLot->id)
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::DISPONIBLE)
        ->and($newLot->status)
        ->toBe(LotStatus::PREVENTA);
});

it('rechaza cambiar el lote cuando el contrato ya tiene actividad financiera', function () {
    $fixture = updateContractFixture();

    $newLot = Lot::factory()->create([
        'project_id' => $fixture['project']->id,
        'status' => LotStatus::DISPONIBLE,
    ]);

    Transaction::create([
        'contract_id' => $fixture['contract']->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT->value,
        'amount' => 500000,
        'transaction_date' => '2026-02-01',
        'payment_method' => PaymentMethod::TRANSFER->value,
        'notes' => 'Pago de prueba',
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'lot_id' => $newLot->id,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract');

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();
    $newLot->refresh();

    expect($fixture['contract']->lot_id)
        ->toBe($fixture['lot']->id)
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::PREVENTA)
        ->and($newLot->status)
        ->toBe(LotStatus::DISPONIBLE);
});

it('permite cambiar el titular cuando el contrato ya tiene actividad financiera', function () {
    $fixture = updateContractFixture();

    Transaction::create([
        'contract_id' => $fixture['contract']->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT->value,
        'amount' => 500000,
        'transaction_date' => '2026-02-01',
        'payment_method' => PaymentMethod::TRANSFER->value,
        'notes' => 'Pago de prueba',
    ]);

    $newCustomer = Customer::factory()->create();

    $payload = updateContractPayload($fixture['contract'], [
        'customer_id' => $newCustomer->id,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();

    expect($fixture['contract']->customer_id)
        ->toBe($newCustomer->id);
});

it('permite modificar los cotitulares cuando el contrato ya tiene actividad financiera', function () {
    $fixture = updateContractFixture();

    Transaction::create([
        'contract_id' => $fixture['contract']->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT->value,
        'amount' => 500000,
        'transaction_date' => '2026-02-01',
        'payment_method' => PaymentMethod::TRANSFER->value,
        'notes' => 'Pago de prueba',
    ]);

    $coTitular = Customer::factory()->create();

    $payload = updateContractPayload($fixture['contract'], [
        'co_titular_ids' => [$coTitular->id],
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();

    expect(
        $fixture['contract']
            ->customers()
            ->whereKey($coTitular->id)
            ->exists()
    )->toBeTrue();
});

it('rechaza que el titular principal sea también cotitular', function () {
    $fixture = updateContractFixture();

    $payload = updateContractPayload($fixture['contract'], [
        'co_titular_ids' => [$fixture['customer']->id],
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('co_titular_ids');
});

it('permite reemplazar los cotitulares cuando el contrato ya tiene actividad financiera', function () {
    $fixture = updateContractFixture();

    $oldCoTitular = Customer::factory()->create();
    $newCoTitular = Customer::factory()->create();

    $fixture['contract']->syncHolders(
        $fixture['customer']->id,
        [$oldCoTitular->id]
    );

    Transaction::create([
        'contract_id' => $fixture['contract']->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT->value,
        'amount' => 500000,
        'transaction_date' => '2026-02-01',
        'payment_method' => PaymentMethod::TRANSFER->value,
        'notes' => 'Pago de prueba',
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'co_titular_ids' => [$newCoTitular->id],
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();

    expect(
        $fixture['contract']
            ->customers()
            ->whereKey($oldCoTitular->id)
            ->exists()
    )->toBeFalse()
        ->and(
            $fixture['contract']
                ->customers()
                ->whereKey($newCoTitular->id)
                ->exists()
        )->toBeTrue();
});

it('rechaza cambiar a un lote que ya está ocupado por otro contrato', function () {
    $fixture = updateContractFixture();

    $occupiedLot = Lot::factory()->create([
        'project_id' => $fixture['project']->id,
        'status' => LotStatus::PREVENTA,
    ]);

    Contract::factory()->create([
        'customer_id' => Customer::factory()->create()->id,
        'lot_id' => $occupiedLot->id,
        'status' => ContractStatus::PREVENTA_INACTIVA,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'lot_id' => $occupiedLot->id,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('lot_id');

    $fixture['contract']->refresh();

    expect($fixture['contract']->lot_id)
        ->toBe($fixture['lot']->id);
});

it('rechaza cambios financieros cuando el contrato no está en preventa inactiva', function () {
    $fixture = updateContractFixture([
        'status' => ContractStatus::ACTIVO,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'sale_price' => 120000000,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract');

    $fixture['contract']->refresh();

    expect((float) $fixture['contract']->sale_price)
        ->toBe(100000000.0);
});

it('permite actualizar campos administrativos cuando el contrato está activo', function () {
    $fixture = updateContractFixture([
        'status' => ContractStatus::ACTIVO,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'seller_name' => 'Vendedor actualizado',
        'contract_number' => 'CTR-ACTIVO-001',
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();

    expect($fixture['contract']->seller_name)
        ->toBe('Vendedor actualizado')
        ->and($fixture['contract']->contract_number)
        ->toBe('CTR-ACTIVO-001');
});

it('rechaza cambiar el lote cuando el contrato no está en preventa inactiva', function () {
    $fixture = updateContractFixture([
        'status' => ContractStatus::ACTIVO,
    ]);

    $newLot = Lot::factory()->create([
        'project_id' => $fixture['project']->id,
        'status' => LotStatus::DISPONIBLE,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'lot_id' => $newLot->id,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract');

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();
    $newLot->refresh();

    expect($fixture['contract']->lot_id)
        ->toBe($fixture['lot']->id)
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::PREVENTA)
        ->and($newLot->status)
        ->toBe(LotStatus::DISPONIBLE);
});

it('permite actualizar campos administrativos cuando el contrato está terminado', function () {
    $fixture = updateContractFixture([
        'status' => ContractStatus::TERMINADO,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'seller_name' => 'Vendedor contrato terminado',
        'contract_number' => 'CTR-TERMINADO-001',
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();

    expect($fixture['contract']->seller_name)
        ->toBe('Vendedor contrato terminado')
        ->and($fixture['contract']->contract_number)
        ->toBe('CTR-TERMINADO-001');
});

it('permite actualizar campos administrativos cuando el contrato está rescindido', function () {
    $fixture = updateContractFixture([
        'status' => ContractStatus::RESCINDIDO,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'seller_name' => 'Vendedor contrato rescindido',
        'contract_number' => 'CTR-RESCINDIDO-001',
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();

    expect($fixture['contract']->seller_name)
        ->toBe('Vendedor contrato rescindido')
        ->and($fixture['contract']->contract_number)
        ->toBe('CTR-RESCINDIDO-001');
});

it('rechaza cambios financieros cuando el contrato está rescindido', function () {
    $fixture = updateContractFixture([
        'status' => ContractStatus::RESCINDIDO,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'sale_price' => 120000000,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract');

    $fixture['contract']->refresh();

    expect((float) $fixture['contract']->sale_price)
        ->toBe(100000000.0);
});

it('rechaza cambios financieros cuando el contrato está terminado', function () {
    $fixture = updateContractFixture([
        'status' => ContractStatus::TERMINADO,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'sale_price' => 120000000,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract');

    $fixture['contract']->refresh();

    expect((float) $fixture['contract']->sale_price)
        ->toBe(100000000.0);
});

it('rechaza cambiar el lote cuando el contrato está rescindido', function () {
    $fixture = updateContractFixture([
        'status' => ContractStatus::RESCINDIDO,
    ]);

    $newLot = Lot::factory()->create([
        'project_id' => $fixture['project']->id,
        'status' => LotStatus::DISPONIBLE,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'lot_id' => $newLot->id,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract');

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();
    $newLot->refresh();

    expect($fixture['contract']->lot_id)
        ->toBe($fixture['lot']->id)
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::PREVENTA)
        ->and($newLot->status)
        ->toBe(LotStatus::DISPONIBLE);
});
it('rechaza cambiar el lote cuando el contrato está terminado', function () {
    $fixture = updateContractFixture([
        'status' => ContractStatus::TERMINADO,
    ]);

    $newLot = Lot::factory()->create([
        'project_id' => $fixture['project']->id,
        'status' => LotStatus::DISPONIBLE,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'lot_id' => $newLot->id,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract');

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();
    $newLot->refresh();

    expect($fixture['contract']->lot_id)
        ->toBe($fixture['lot']->id)
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::PREVENTA)
        ->and($newLot->status)
        ->toBe(LotStatus::DISPONIBLE);
});

it('elimina las promesas comerciales al cambiar de plan personalizado a plan estándar', function () {
    $fixture = updateContractFixture([
        'is_custom_plan' => true,
    ]);

    ContractPaymentPromise::create([
        'contract_id' => $fixture['contract']->id,
        'payment_number' => 1,
        'expected_date' => '2026-02-01',
        'expected_amount' => 5000000,
        'description' => 'Promesa comercial',
        'is_paid' => false,
    ]);

    ContractPaymentPromise::create([
        'contract_id' => $fixture['contract']->id,
        'payment_number' => 2,
        'expected_date' => '2026-03-01',
        'expected_amount' => 5000000,
        'description' => 'Promesa comercial',
        'is_paid' => false,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'is_custom_plan' => false,
        'promises' => null,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    expect(
        ContractPaymentPromise::query()
            ->where('contract_id', $fixture['contract']->id)
            ->exists()
    )->toBeFalse();
});

it('conserva las promesas de refinanciación al cambiar a plan estándar', function () {
    $fixture = updateContractFixture([
        'is_custom_plan' => true,
    ]);

    ContractPaymentPromise::create([
        'contract_id' => $fixture['contract']->id,
        'payment_number' => 1,
        'expected_date' => '2026-02-01',
        'expected_amount' => 5000000,
        'description' => 'Promesa comercial',
        'is_paid' => false,
    ]);

    ContractPaymentPromise::create([
        'contract_id' => $fixture['contract']->id,
        'payment_number' => 2,
        'expected_date' => '2026-03-01',
        'expected_amount' => 1000000,
        'description' => \App\Services\Financial\Refinancing\AcuerdoPagoService::DESCRIPTION,
        'is_paid' => false,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'is_custom_plan' => false,
        'promises' => null,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    expect(
        ContractPaymentPromise::query()
            ->where('contract_id', $fixture['contract']->id)
            ->where(
                'description',
                \App\Services\Financial\Refinancing\AcuerdoPagoService::DESCRIPTION
            )
            ->exists()
    )->toBeTrue();

    expect(
        ContractPaymentPromise::query()
            ->where('contract_id', $fixture['contract']->id)
            ->where('description', 'Promesa comercial')
            ->exists()
    )->toBeFalse();
});

it('actualiza las promesas comerciales de un plan personalizado', function () {
    $fixture = updateContractFixture([
        'is_custom_plan' => true,
    ]);

    ContractPaymentPromise::create([
        'contract_id' => $fixture['contract']->id,
        'payment_number' => 1,
        'expected_date' => '2026-02-01',
        'expected_amount' => 5000000,
        'description' => 'Promesa comercial anterior',
        'is_paid' => false,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'is_custom_plan' => true,
        'promises' => [
            [
                'payment_number' => 1,
                'expected_date' => '2026-03-01',
                'expected_amount' => 7000000,
                'description' => 'Nueva promesa comercial',
            ],
            [
                'payment_number' => 2,
                'expected_date' => '2026-04-01',
                'expected_amount' => 8000000,
                'description' => 'Nueva promesa comercial',
            ],
        ],
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    $promises = ContractPaymentPromise::query()
        ->where('contract_id', $fixture['contract']->id)
        ->get();

    expect($promises)->toHaveCount(2)
        ->and($promises->pluck('description')->toArray())
        ->toBe([
            'Nueva promesa comercial',
            'Nueva promesa comercial',
        ]);
});

it('rechaza modificar las promesas comerciales cuando existe actividad financiera', function () {
    $fixture = updateContractFixture([
        'is_custom_plan' => true,
    ]);

    ContractPaymentPromise::create([
        'contract_id' => $fixture['contract']->id,
        'payment_number' => 1,
        'expected_date' => '2026-02-01',
        'expected_amount' => 5000000,
        'description' => 'Promesa comercial original',
        'is_paid' => false,
    ]);

    Transaction::create([
        'contract_id' => $fixture['contract']->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT->value,
        'amount' => 500000,
        'transaction_date' => '2026-02-01',
        'payment_method' => PaymentMethod::TRANSFER->value,
        'notes' => 'Pago de prueba',
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'is_custom_plan' => true,
        'promises' => [
            [
                'payment_number' => 1,
                'expected_date' => '2026-03-01',
                'expected_amount' => 7000000,
                'description' => 'Nueva promesa comercial',
            ],
        ],
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('promises');

    expect(
        ContractPaymentPromise::query()
            ->where('contract_id', $fixture['contract']->id)
            ->where('description', 'Promesa comercial original')
            ->exists()
    )->toBeTrue();

    expect(
        ContractPaymentPromise::query()
            ->where('contract_id', $fixture['contract']->id)
            ->where('description', 'Nueva promesa comercial')
            ->exists()
    )->toBeFalse();
});

it('rechaza cambiar de plan personalizado a estándar cuando existe actividad financiera', function () {
    $fixture = updateContractFixture([
        'is_custom_plan' => true,
    ]);

    ContractPaymentPromise::create([
        'contract_id' => $fixture['contract']->id,
        'payment_number' => 1,
        'expected_date' => '2026-02-01',
        'expected_amount' => 5000000,
        'description' => 'Promesa comercial',
        'is_paid' => false,
    ]);

    Transaction::create([
        'contract_id' => $fixture['contract']->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT->value,
        'amount' => 500000,
        'transaction_date' => '2026-02-01',
        'payment_method' => PaymentMethod::TRANSFER->value,
        'notes' => 'Pago de prueba',
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'is_custom_plan' => false,
        'promises' => null,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract');

    $fixture['contract']->refresh();

    expect($fixture['contract']->is_custom_plan)
        ->toBeTrue();

    expect(
        ContractPaymentPromise::query()
            ->where('contract_id', $fixture['contract']->id)
            ->where('description', 'Promesa comercial')
            ->exists()
    )->toBeTrue();
});

it('rechaza usar un número de contrato que ya pertenece a otro contrato', function () {
    $fixture = updateContractFixture();

    $otherCustomer = Customer::factory()->create();

    $otherLot = Lot::factory()->create([
        'project_id' => $fixture['project']->id,
        'status' => LotStatus::DISPONIBLE,
    ]);

    $otherContract = Contract::factory()->create([
        'customer_id' => $otherCustomer->id,
        'lot_id' => $otherLot->id,
        'status' => ContractStatus::PREVENTA_INACTIVA,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'contract_number' => $otherContract->contract_number,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('contract_number');

    $fixture['contract']->refresh();

    expect($fixture['contract']->contract_number)
        ->not->toBe($otherContract->contract_number);
});

it('rechaza asignar como titular principal a un cliente que ya es cotitular', function () {
    $fixture = updateContractFixture();

    $coTitular = Customer::factory()->create();

    $fixture['contract']->syncHolders(
        $fixture['customer']->id,
        [$coTitular->id]
    );

    $payload = updateContractPayload($fixture['contract'], [
        'customer_id' => $coTitular->id,
        'co_titular_ids' => [$coTitular->id],
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('co_titular_ids');
});

it('rechaza asignar un cliente inexistente como titular principal', function () {
    $fixture = updateContractFixture();

    $nonExistentCustomerId = Customer::query()->max('id') + 1;

    $payload = updateContractPayload($fixture['contract'], [
        'customer_id' => $nonExistentCustomerId,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('customer_id');

    $fixture['contract']->refresh();

    expect($fixture['contract']->customer_id)
        ->toBe($fixture['customer']->id);
});

it('rechaza asignar un cotitular inexistente', function () {
    $fixture = updateContractFixture();

    $nonExistentCustomerId = Customer::query()->max('id') + 1;

    $payload = updateContractPayload($fixture['contract'], [
        'co_titular_ids' => [$nonExistentCustomerId],
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('co_titular_ids.0');

    $fixture['contract']->refresh();

    expect(
        $fixture['contract']
            ->customers()
            ->whereKey($nonExistentCustomerId)
            ->exists()
    )->toBeFalse();
});

it('rechaza asignar un lote inexistente al contrato', function () {
    $fixture = updateContractFixture();

    $nonExistentLotId = Lot::query()->max('id') + 1;

    $payload = updateContractPayload($fixture['contract'], [
        'lot_id' => $nonExistentLotId,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('lot_id');

    $fixture['contract']->refresh();

    expect($fixture['contract']->lot_id)
        ->toBe($fixture['lot']->id);
});

it('rechaza cambiar a un lote de otro proyecto', function () {
    $fixture = updateContractFixture();

    $otherProject = Project::create([
        'name' => 'Segundo proyecto',
        'description' => 'Otro proyecto para pruebas',
        'location' => 'Cali',
        'status' => 'activo',
    ]);

    $otherLot = Lot::factory()->create([
        'project_id' => $otherProject->id,
        'status' => LotStatus::DISPONIBLE,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'lot_id' => $otherLot->id,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('lot_id');

    $fixture['contract']->refresh();

    expect($fixture['contract']->lot_id)
        ->toBe($fixture['lot']->id);
});

it('permite cambiar a un lote que tuvo un contrato rescindido', function () {
    $fixture = updateContractFixture();

    $availableLot = Lot::factory()->create([
        'project_id' => $fixture['project']->id,
        'status' => LotStatus::DISPONIBLE,
    ]);

    $previousCustomer = Customer::factory()->create();

    Contract::factory()->create([
        'customer_id' => $previousCustomer->id,
        'lot_id' => $availableLot->id,
        'status' => ContractStatus::RESCINDIDO,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'lot_id' => $availableLot->id,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();
    $availableLot->refresh();

    expect($fixture['contract']->lot_id)
        ->toBe($availableLot->id)
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::DISPONIBLE)
        ->and($availableLot->status)
        ->toBe(LotStatus::PREVENTA);
});

it('rechaza cambiar a un lote con contrato rescindido si el lote no está disponible', function () {
    $fixture = updateContractFixture();

    $lotWithRescindedContract = Lot::factory()->create([
        'project_id' => $fixture['project']->id,
        'status' => LotStatus::PREVENTA,
    ]);

    $previousCustomer = Customer::factory()->create();

    Contract::factory()->create([
        'customer_id' => $previousCustomer->id,
        'lot_id' => $lotWithRescindedContract->id,
        'status' => ContractStatus::RESCINDIDO,
    ]);

    $payload = updateContractPayload($fixture['contract'], [
        'lot_id' => $lotWithRescindedContract->id,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors('lot_id');

    $fixture['contract']->refresh();

    expect($fixture['contract']->lot_id)
        ->toBe($fixture['lot']->id);
});

it('permite actualizar el contrato conservando el mismo lote', function () {
    $fixture = updateContractFixture();

    $payload = updateContractPayload($fixture['contract'], [
        'seller_name' => 'Vendedor actualizado',
        'lot_id' => $fixture['lot']->id,
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();
    $fixture['lot']->refresh();

    expect($fixture['contract']->lot_id)
        ->toBe($fixture['lot']->id)
        ->and($fixture['lot']->status)
        ->toBe(LotStatus::PREVENTA);
});

it('permite actualizar únicamente el número de contrato sin modificar las condiciones financieras', function () {
    $fixture = updateContractFixture();

    $payload = updateContractPayload($fixture['contract'], [
        'contract_number' => 'CTR-SOLO-NUMERO-001',
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();

    expect($fixture['contract']->contract_number)
        ->toBe('CTR-SOLO-NUMERO-001')
        ->and((float) $fixture['contract']->sale_price)
        ->toBe(100000000.0)
        ->and((float) $fixture['contract']->down_payment_pactada)
        ->toBe(20000000.0)
        ->and((int) $fixture['contract']->term_months)
        ->toBe(60)
        ->and((float) $fixture['contract']->interest_rate)
        ->toBe(1.0);
});

it('permite actualizar únicamente el vendedor sin modificar las condiciones financieras', function () {
    $fixture = updateContractFixture();

    $payload = updateContractPayload($fixture['contract'], [
        'seller_name' => 'Vendedor nuevo',
    ]);

    $response = $this
        ->actingAs($fixture['user'])
        ->putJson(
            "/api/contracts/{$fixture['contract']->id}",
            $payload
        );

    $response->assertSuccessful();

    $fixture['contract']->refresh();

    expect($fixture['contract']->seller_name)
        ->toBe('Vendedor nuevo')
        ->and((float) $fixture['contract']->sale_price)
        ->toBe(100000000.0)
        ->and((float) $fixture['contract']->down_payment_pactada)
        ->toBe(20000000.0)
        ->and((int) $fixture['contract']->term_months)
        ->toBe(60)
        ->and((float) $fixture['contract']->interest_rate)
        ->toBe(1.0);
});
