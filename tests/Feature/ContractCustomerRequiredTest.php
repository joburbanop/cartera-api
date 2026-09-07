<?php

use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function contractPayload(array $overrides = []): array
{
    $project = Project::query()->create([
        'name' => 'Proyecto alta cliente '.uniqid(),
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'status' => 'disponible',
    ]);

    return array_merge([
        'contract_number' => 'PROM-CLI-'.uniqid(),
        'lot_id' => $lot->id,
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
    ], $overrides);
}

it('responde 422 si no hay customer_id válido ni nombre, documento y teléfono', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $this->postJson('/api/contracts', contractPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer_name', 'customer_document', 'customer_phone']);

    expect(Customer::query()->count())->toBe(0);
});

it('responde 422 si el customer_id no existe', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $this->postJson('/api/contracts', contractPayload([
        'customer_id' => 999999,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer_id']);
});

it('crea el cliente en línea con nombre, documento y teléfono reales', function () {
    User::factory()->create();
    $admin = $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $this->postJson('/api/contracts', contractPayload([
        'customer_name' => 'María López',
        'customer_document' => '1098765430',
        'customer_phone' => '3001112233',
        'customer_email' => 'maria.lopez@example.com',
    ]))->assertCreated();

    $customer = Customer::query()->where('document_number', '1098765430')->first();

    expect($customer)->not->toBeNull()
        ->and($customer->name)->toBe('María López')
        ->and($customer->phone)->toBe('3001112233')
        ->and($customer->created_by)->toBe($admin->id)
        ->and($customer->name)->not->toBe('Cliente de Prueba');
});

it('no atribuye created_by al usuario 1 al crear un cliente autenticado', function () {
    User::factory()->create();
    $admin = $this->actingAsRole(RoleName::ADMINISTRADOR->value);
    expect($admin->id)->not->toBe(1);

    $this->postJson('/api/customers', [
        'document_type' => 'CC',
        'document_number' => '1088001122',
        'name' => 'Cliente Atribuido',
        'phone' => '3002223344',
    ])->assertCreated();

    $customer = Customer::query()->where('document_number', '1088001122')->firstOrFail();

    expect($customer->created_by)->toBe($admin->id);
});
