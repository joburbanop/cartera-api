<?php

use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Enums\WithdrawalCause;
use App\Enums\WithdrawalStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Enums\WithdrawalType;

uses(RefreshDatabase::class);

it('registra un desistimiento en preventa mediante el endpoint', function () {
    $permission = Permission::findOrCreate(
        'contracts.rescind',
        'web'
    );

    $role = Role::findOrCreate(
        'Administrador',
        'web'
    );

    $role->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($role);

    $project = Project::create([
        'name' => 'Proyecto Withdrawal HTTP',
        'description' => 'Proyecto de prueba',
        'location' => 'Cali',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '900001',
        'name' => 'Cliente Withdrawal HTTP',
        'phone' => '3000000001',
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'WD-HTTP-001',
        'area_m2' => 100,
        'price_m2' => 1_000_000,
        'list_price' => 160_000_000,
        'status' => LotStatus::PREVENTA,
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-WD-HTTP-001',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 160_000_000,
        'down_payment_pactada' => 30_000_000,
        'term_months' => 12,
        'interest_rate' => 0,
        'start_date' => '2026-09-01',
        'initial_payment_date' => '2026-09-01',
        'first_installment_date' => '2026-10-01',
        'regular_payment_start_date' => '2026-10-01',
        'preventa_installments_count' => 3,
        'status' => ContractStatus::PREVENTA_INACTIVA,
    ]);

    Transaction::create([
        'contract_id' => $contract->id,
        'transaction_type' => 'down_payment',
        'amount' => 10_500_000,
        'transaction_date' => '2026-09-05',
        'payment_method' => 'transfer',
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/withdrawals/preventa', [
            'contract_id' => $contract->id,
            'request_date' => '2026-09-10',
            'cause' => WithdrawalCause::RETRACTO_DE_LEY->value,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.contract_id', $contract->id)
        ->assertJsonPath('data.cause', WithdrawalCause::RETRACTO_DE_LEY->value)
        ->assertJsonPath('data.refund_balance', '10500000.00');

    $this->assertDatabaseHas('withdrawals', [
        'contract_id' => $contract->id,
        'cause' => WithdrawalCause::RETRACTO_DE_LEY->value,
        'refund_balance' => '10500000.00',
        'status' => WithdrawalStatus::PENDING->value,
    ]);

    $this->assertDatabaseHas('contracts', [
        'id' => $contract->id,
        'status' => ContractStatus::RESCINDIDO->value,
    ]);

    $this->assertDatabaseHas('lots', [
        'id' => $lot->id,
        'status' => LotStatus::DISPONIBLE->value,
    ]);
});

it('registra un desistimiento con devolución pendiente y actualiza contrato y lote', function () {
    $permission = Permission::findOrCreate(
        'contracts.rescind',
        'web'
    );

    $role = Role::findOrCreate(
        'Administrador',
        'web'
    );

    $role->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($role);

    $project = Project::create([
        'name' => 'Proyecto Withdrawal Pending',
        'description' => 'Proyecto de prueba',
        'location' => 'Cali',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '900002',
        'name' => 'Cliente Withdrawal Pending',
        'phone' => '3000000002',
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'WD-PENDING-001',
        'area_m2' => 100,
        'price_m2' => 1_000_000,
        'list_price' => 160_000_000,
        'status' => LotStatus::PREVENTA,
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-WD-PENDING-001',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 160_000_000,
        'down_payment_pactada' => 30_000_000,
        'term_months' => 12,
        'interest_rate' => 0,
        'start_date' => '2026-09-01',
        'initial_payment_date' => '2026-09-01',
        'first_installment_date' => '2026-10-01',
        'regular_payment_start_date' => '2026-10-01',
        'preventa_installments_count' => 3,
        'status' => ContractStatus::PREVENTA_INACTIVA,
    ]);

    Transaction::create([
        'contract_id' => $contract->id,
        'transaction_type' => 'down_payment',
        'amount' => 10_500_000,
        'transaction_date' => '2026-09-05',
        'payment_method' => 'transfer',
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/withdrawals/preventa', [
            'contract_id' => $contract->id,
            'request_date' => '2026-09-10',
            'cause' => WithdrawalCause::RETRACTO_DE_LEY->value,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.contract_id', $contract->id)
        ->assertJsonPath('data.refund_balance', '10500000.00')
        ->assertJsonPath('data.status', WithdrawalStatus::PENDING->value);

    $this->assertDatabaseHas('withdrawals', [
        'contract_id' => $contract->id,
        'refund_balance' => '10500000.00',
        'status' => WithdrawalStatus::PENDING->value,
    ]);

    $this->assertDatabaseHas('contracts', [
        'id' => $contract->id,
        'status' => ContractStatus::RESCINDIDO->value,
    ]);

    $this->assertDatabaseHas('lots', [
        'id' => $lot->id,
        'status' => LotStatus::DISPONIBLE->value,
    ]);
});

it('completa el desistimiento cuando la multa absorbe los aportes', function () {
    $permission = Permission::findOrCreate(
        'contracts.rescind',
        'web'
    );

    $role = Role::findOrCreate(
        'Administrador',
        'web'
    );

    $role->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($role);

    $project = Project::create([
        'name' => 'Proyecto Withdrawal Completed',
        'description' => 'Proyecto de prueba',
        'location' => 'Cali',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '900003',
        'name' => 'Cliente Withdrawal Completed',
        'phone' => '3000000003',
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'WD-COMPLETED-001',
        'area_m2' => 100,
        'price_m2' => 1_000_000,
        'list_price' => 160_000_000,
        'status' => LotStatus::PREVENTA,
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-WD-COMPLETED-001',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 160_000_000,
        'down_payment_pactada' => 30_000_000,
        'term_months' => 12,
        'interest_rate' => 0,
        'start_date' => '2026-09-01',
        'initial_payment_date' => '2026-09-01',
        'first_installment_date' => '2026-10-01',
        'regular_payment_start_date' => '2026-10-01',
        'preventa_installments_count' => 3,
        'status' => ContractStatus::PREVENTA_INACTIVA,
    ]);

    Transaction::create([
        'contract_id' => $contract->id,
        'transaction_type' => 'down_payment',
        'amount' => 1_000_000,
        'transaction_date' => '2026-09-05',
        'payment_method' => 'transfer',
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/withdrawals/preventa', [
            'contract_id' => $contract->id,
            'request_date' => '2026-09-10',
            'cause' => WithdrawalCause::VOLUNTARIO->value,
            'retention_percentage' => 100,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.contract_id', $contract->id)
        ->assertJsonPath('data.penalty_amount', '1000000.00')
        ->assertJsonPath('data.refund_balance', '0.00')
        ->assertJsonPath('data.status', WithdrawalStatus::COMPLETED->value);

    $this->assertDatabaseHas('withdrawals', [
        'contract_id' => $contract->id,
        'cause' => WithdrawalCause::VOLUNTARIO->value,
        'authorized_retention_percentage' => '100.00',
        'penalty_amount' => '1000000.00',
        'refund_balance' => '0.00',
        'status' => WithdrawalStatus::COMPLETED->value,
    ]);

    $this->assertDatabaseHas('contracts', [
        'id' => $contract->id,
        'status' => ContractStatus::RESCINDIDO->value,
    ]);

    $this->assertDatabaseHas('lots', [
        'id' => $lot->id,
        'status' => LotStatus::DISPONIBLE->value,
    ]);
});

it('rechaza el desistimiento cuando el contrato no está en preventa inactiva', function () {
    $permission = Permission::findOrCreate(
        'contracts.rescind',
        'web'
    );

    $role = Role::findOrCreate(
        'Administrador',
        'web'
    );

    $role->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($role);

    $project = Project::create([
        'name' => 'Proyecto Withdrawal Invalid Status',
        'description' => 'Proyecto de prueba',
        'location' => 'Cali',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '900004',
        'name' => 'Cliente Withdrawal Invalid Status',
        'phone' => '3000000004',
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'WD-INVALID-001',
        'area_m2' => 100,
        'price_m2' => 1_000_000,
        'list_price' => 160_000_000,
        'status' => LotStatus::PREVENTA,
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-WD-INVALID-001',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 160_000_000,
        'down_payment_pactada' => 30_000_000,
        'term_months' => 12,
        'interest_rate' => 0,
        'start_date' => '2026-09-01',
        'initial_payment_date' => '2026-09-01',
        'first_installment_date' => '2026-10-01',
        'regular_payment_start_date' => '2026-10-01',
        'preventa_installments_count' => 3,
        'status' => ContractStatus::ACTIVO,
    ]);

    Transaction::create([
        'contract_id' => $contract->id,
        'transaction_type' => 'down_payment',
        'amount' => 10_500_000,
        'transaction_date' => '2026-09-05',
        'payment_method' => 'transfer',
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/withdrawals/preventa', [
            'contract_id' => $contract->id,
            'request_date' => '2026-09-10',
            'cause' => WithdrawalCause::RETRACTO_DE_LEY->value,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'El contrato no se encuentra en estado de preventa inactiva.');

    $this->assertDatabaseMissing('withdrawals', [
        'contract_id' => $contract->id,
    ]);

    $this->assertDatabaseHas('contracts', [
        'id' => $contract->id,
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $this->assertDatabaseHas('lots', [
        'id' => $lot->id,
        'status' => LotStatus::PREVENTA->value,
    ]);
});

it('rechaza la solicitud cuando no se especifica la causa', function () {
    $permission = Permission::findOrCreate(
        'contracts.rescind',
        'web'
    );

    $role = Role::findOrCreate(
        'Administrador',
        'web'
    );

    $role->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($role);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/withdrawals/preventa', [
            'contract_id' => 999999,
            'request_date' => '2026-09-10',
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cause']);
});

it('rechaza la solicitud cuando no se especifica el contrato', function () {
    $permission = Permission::findOrCreate(
        'contracts.rescind',
        'web'
    );

    $role = Role::findOrCreate(
        'Administrador',
        'web'
    );

    $role->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($role);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/withdrawals/preventa', [
            'request_date' => '2026-09-10',
            'cause' => WithdrawalCause::RETRACTO_DE_LEY->value,
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['contract_id']);
});

it('rechaza la solicitud cuando no se especifica la fecha', function () {
    $permission = Permission::findOrCreate(
        'contracts.rescind',
        'web'
    );

    $role = Role::findOrCreate(
        'Administrador',
        'web'
    );

    $role->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($role);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/withdrawals/preventa', [
            'contract_id' => 999999,
            'cause' => WithdrawalCause::RETRACTO_DE_LEY->value,
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['request_date']);
});

it('rechaza un contrato inexistente', function () {
    $permission = Permission::findOrCreate(
        'contracts.rescind',
        'web'
    );

    $role = Role::findOrCreate(
        'Administrador',
        'web'
    );

    $role->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($role);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/withdrawals/preventa', [
            'contract_id' => 999999,
            'request_date' => '2026-09-10',
            'cause' => WithdrawalCause::RETRACTO_DE_LEY->value,
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['contract_id']);
});

it('rechaza el acceso al endpoint sin el permiso de desistimiento', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->postJson('/api/withdrawals/preventa', [
            'contract_id' => 999999,
            'request_date' => '2026-09-10',
            'cause' => WithdrawalCause::RETRACTO_DE_LEY->value,
        ]);

    $response->assertForbidden();
});

it('calcula la liquidación de un desistimiento en preventa mediante el endpoint', function () {
    $permission = Permission::findOrCreate(
        'contracts.rescind',
        'web'
    );

    $role = Role::findOrCreate(
        'Administrador',
        'web'
    );

    $role->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($role);

    $project = Project::create([
        'name' => 'Proyecto Withdrawal Calculate',
        'description' => 'Proyecto de prueba',
        'location' => 'Cali',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '900008',
        'name' => 'Cliente Withdrawal Calculate',
        'phone' => '3000000008',
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'WD-CALCULATE-001',
        'area_m2' => 100,
        'price_m2' => 1_000_000,
        'list_price' => 160_000_000,
        'status' => LotStatus::PREVENTA,
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-WD-CALCULATE-001',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 160_000_000,
        'down_payment_pactada' => 30_000_000,
        'term_months' => 12,
        'interest_rate' => 0,
        'start_date' => '2026-09-01',
        'initial_payment_date' => '2026-09-01',
        'first_installment_date' => '2026-10-01',
        'regular_payment_start_date' => '2026-10-01',
        'preventa_installments_count' => 3,
        'status' => ContractStatus::PREVENTA_INACTIVA,
    ]);

    Transaction::create([
        'contract_id' => $contract->id,
        'transaction_type' => 'down_payment',
        'amount' => 10_000_000,
        'transaction_date' => '2026-09-05',
        'payment_method' => 'transfer',
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/api/withdrawals/preventa/calculate', [
            'contract_id' => $contract->id,
            'request_date' => '2026-09-10',
            'cause' => WithdrawalCause::VOLUNTARIO->value,
            'retention_percentage' => 10,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.type', WithdrawalType::PREVENTA->value)
        ->assertJsonPath('data.cause', WithdrawalCause::VOLUNTARIO->value)
        ->assertJsonPath('data.contributions', 10000000)
        ->assertJsonPath('data.standard_retention_percentage', 10)
        ->assertJsonPath('data.authorized_retention_percentage', 10)
        ->assertJsonPath('data.penalty_amount', 1000000)
        ->assertJsonPath('data.refund_balance', 9000000);

    $this->assertDatabaseMissing('withdrawals', [
        'contract_id' => $contract->id,
    ]);

    $this->assertDatabaseHas('contracts', [
        'id' => $contract->id,
        'status' => ContractStatus::PREVENTA_INACTIVA->value,
    ]);

    $this->assertDatabaseHas('lots', [
        'id' => $lot->id,
        'status' => LotStatus::PREVENTA->value,
    ]);
});