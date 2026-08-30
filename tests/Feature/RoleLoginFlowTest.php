<?php

use App\Enums\AmortizationStatus;
use App\Enums\RoleName;
use App\Models\AmortizationInstallment;
use App\Models\BankAccount;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create([
        'name' => 'Administrador',
        'email' => 'admin@admin.com',
        'password' => Hash::make('password'),
    ]);
    $this->admin->syncRoles([RoleName::ADMINISTRADOR->value]);

    $this->socio = User::factory()->create([
        'name' => 'Socio Gerencia',
        'email' => 'socio@cartera.test',
        'password' => Hash::make('password'),
    ]);
    $this->socio->syncRoles([RoleName::SOCIO_GERENCIA->value]);

    $this->sistema = User::factory()->create([
        'name' => 'Admin Sistema',
        'email' => 'sistema@cartera.test',
        'password' => Hash::make('password'),
    ]);
    $this->sistema->syncRoles([RoleName::ADMIN_SISTEMA->value]);

    $this->account = BankAccount::query()->create([
        'bank_name' => 'Bancolombia',
        'account_number' => '1111222233',
        'account_type' => 'savings',
        'holder_name' => 'Constructora QA',
        'is_active' => true,
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
    ]);

    $this->project = Project::query()->create([
        'name' => 'Proyecto Flujo Roles',
        'description' => 'Fixture login flow',
        'location' => 'Bogotá',
        'status' => 'active',
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
    ]);

    $this->customer = Customer::factory()->create();
    $this->lot = Lot::factory()->create(['project_id' => $this->project->id]);
    $this->freeLot = Lot::factory()->create(['project_id' => $this->project->id]);

    $this->contract = Contract::factory()->create([
        'customer_id' => $this->customer->id,
        'lot_id' => $this->lot->id,
        'sale_price' => 7000000,
        'down_payment_pactada' => 2000000,
        'term_months' => 5,
        'interest_rate' => 0,
        'status' => 'activo',
        'start_date' => now()->subMonths(2)->toDateString(),
        'initial_payment_date' => now()->subMonths(2)->toDateString(),
        'first_installment_date' => now()->subMonth()->toDateString(),
        'regular_payment_start_date' => now()->subMonth()->toDateString(),
        'preventa_installments_count' => 0,
    ]);

    $this->installment = AmortizationInstallment::query()->create([
        'contract_id' => $this->contract->id,
        'installment_number' => 1,
        'due_date' => now()->toDateString(),
        'installment_value' => '1000000.00',
        'extra_payment' => '0.00',
        'interest_value' => '0.00',
        'principal_value' => '1000000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000000.00',
        'remaining_balance' => '5000000.00',
        'projected_balance' => '5000000.00',
        'status' => AmortizationStatus::PENDING->value,
    ]);
});

function loginAs(string $email): string
{
    $response = test()->postJson('/api/login', [
        'email' => $email,
        'password' => 'password',
    ]);

    $response->assertOk()->assertJsonPath('data.access_token', fn ($token) => is_string($token) && $token !== '');

    return $response->json('data.access_token');
}

it('loguea administrador y confirma escrituras de negocio y bloqueo de usuarios', function () {
    $token = loginAs('admin@admin.com');
    $headers = ['Authorization' => "Bearer {$token}"];

    $me = $this->getJson('/api/me', $headers);
    $me->assertOk()->assertJsonPath('data.roles.0', RoleName::ADMINISTRADOR->value);

    $this->postJson('/api/bank-accounts', [
        'bank_name' => 'Davivienda',
        'account_number' => '5555666677',
        'account_type' => 'savings',
        'holder_name' => 'Constructora San Miguel',
    ], $headers)->assertCreated();

    $this->postJson('/api/customers', [
        'document_type' => 'CC',
        'document_number' => '1098765432',
        'name' => 'Cliente Flujo',
        'phone' => '3001234567',
        'email' => 'cliente.flujo@example.com',
    ], $headers)->assertCreated();

    $this->postJson('/api/projects', [
        'name' => 'Proyecto Login Admin',
        'location' => 'Medellín',
        'bank_account_ids' => [$this->account->id],
    ], $headers)->assertCreated();

    $this->postJson('/api/lots', [
        'project_id' => $this->project->id,
        'number' => 'L-LOGIN-01',
        'area_m2' => 120,
        'price_m2' => 1000000,
        'list_price' => 120000000,
        'status' => 'disponible',
    ], $headers)->assertCreated();

    $this->postJson('/api/contracts', [
        'contract_number' => 'PROM-LOGIN-001',
        'customer_id' => $this->customer->id,
        'lot_id' => $this->freeLot->id,
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
    ], $headers)->assertCreated();

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $this->contract->id,
        'amount' => 1000000,
        'selected_installments' => [$this->installment->id],
    ], $headers)->assertCreated();

    $this->getJson('/api/users', $headers)->assertForbidden();
    $this->postJson('/api/users', [
        'name' => 'No debe',
        'email' => 'nodesdeadmin@example.com',
        'password' => 'password',
        'role' => RoleName::ADMINISTRADOR->value,
    ], $headers)->assertForbidden();
});

it('loguea socio_gerencia y confirma lectura 200 y escritura 403', function () {
    $token = loginAs('socio@cartera.test');
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->getJson('/api/me', $headers)
        ->assertOk()
        ->assertJsonPath('data.roles.0', RoleName::SOCIO_GERENCIA->value);

    $this->getJson('/api/projects', $headers)->assertOk();
    $this->getJson('/api/lots', $headers)->assertOk();
    $this->getJson('/api/contracts', $headers)->assertOk();
    $this->getJson("/api/contracts/{$this->contract->id}/amortization", $headers)->assertOk();
    $this->getJson('/api/transactions', $headers)->assertOk();
    $this->getJson("/api/contracts/{$this->contract->id}/transactions", $headers)->assertOk();

    $this->postJson('/api/projects', [
        'name' => 'Proyecto Socio',
        'location' => 'Cali',
        'bank_account_ids' => [$this->account->id],
    ], $headers)->assertForbidden();

    $this->postJson('/api/lots', [
        'project_id' => $this->project->id,
        'number' => 'L-SOCIO',
        'area_m2' => 80,
        'price_m2' => 1000000,
        'list_price' => 80000000,
    ], $headers)->assertForbidden();

    $this->postJson('/api/contracts', [
        'contract_number' => 'PROM-SOCIO-001',
        'customer_id' => $this->customer->id,
        'lot_id' => $this->freeLot->id,
        'sale_price' => 7000000,
        'down_payment_pactada' => 2000000,
        'term_months' => 5,
        'interest_rate' => 0,
        'start_date' => now()->toDateString(),
        'initial_payment_date' => now()->toDateString(),
        'first_installment_date' => now()->addMonth()->toDateString(),
        'preventa_installments_count' => 0,
    ], $headers)->assertForbidden();

    $this->postJson('/api/customers', [
        'document_type' => 'CC',
        'document_number' => '1010101010',
        'name' => 'Cliente Socio',
        'phone' => '3000000000',
    ], $headers)->assertForbidden();

    $this->postJson('/api/bank-accounts', [
        'bank_name' => 'BBVA',
        'account_number' => '00001111',
        'account_type' => 'savings',
        'holder_name' => 'Titular',
    ], $headers)->assertForbidden();

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $this->contract->id,
        'amount' => 1000000,
        'selected_installments' => [$this->installment->id],
    ], $headers)->assertForbidden();

    $this->putJson("/api/users/{$this->socio->id}", ['name' => 'Hack'], $headers)->assertForbidden();
    $this->deleteJson("/api/users/{$this->admin->id}", $headers)->assertForbidden();
    $this->getJson('/api/users', $headers)->assertForbidden();
});

it('loguea admin_sistema y confirma CRUD de usuarios y bloqueo de proyectos', function () {
    $token = loginAs('sistema@cartera.test');
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->getJson('/api/me', $headers)
        ->assertOk()
        ->assertJsonPath('data.roles.0', RoleName::ADMIN_SISTEMA->value);

    $this->getJson('/api/users', $headers)->assertOk();

    $created = $this->postJson('/api/users', [
        'name' => 'Usuario CRUD',
        'email' => 'crud.sistema@example.com',
        'password' => 'password',
        'role' => RoleName::ADMINISTRADOR->value,
    ], $headers);

    $created->assertCreated()->assertJsonPath('data.roles.0', RoleName::ADMINISTRADOR->value);
    $userId = $created->json('data.id');

    $this->putJson("/api/users/{$userId}", [
        'name' => 'Usuario CRUD Editado',
        'role' => RoleName::SOCIO_GERENCIA->value,
    ], $headers)->assertOk()
        ->assertJsonPath('data.name', 'Usuario CRUD Editado')
        ->assertJsonPath('data.roles.0', RoleName::SOCIO_GERENCIA->value);

    $this->deleteJson("/api/users/{$userId}", $headers)->assertOk();

    $this->postJson('/api/projects', [
        'name' => 'Proyecto Sistema',
        'location' => 'Barranquilla',
        'bank_account_ids' => [$this->account->id],
    ], $headers)->assertForbidden();
});
