<?php

use App\Enums\DocumentType;
use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    User::factory()->create();
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);
});

function customerPayload(array $overrides = []): array
{
    return array_merge([
        'document_type' => 'CC',
        'document_number' => '1088001122',
        'name' => 'Cliente Completo',
        'phone' => '3002223344',
        'email' => 'cliente.completo@example.com',
        'address' => 'Calle 1 # 2-3',
        'city' => 'Cali',
    ], $overrides);
}

it('crea el cliente cuando vienen todos los datos de contacto', function () {
    $this->postJson('/api/customers', customerPayload())->assertCreated();

    $customer = Customer::query()->where('document_number', '1088001122')->firstOrFail();

    expect($customer->email)->toBe('cliente.completo@example.com')
        ->and($customer->address)->toBe('Calle 1 # 2-3')
        ->and($customer->city)->toBe('Cali');
});

it('exige correo, dirección y ciudad al crear', function (string $field) {
    $payload = customerPayload();
    unset($payload[$field]);

    $this->postJson('/api/customers', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with(['email', 'address', 'city']);

it('acepta NIT como tipo de documento', function () {
    $this->postJson('/api/customers', customerPayload([
        'document_type' => DocumentType::NIT->value,
        'document_number' => '900123456-7',
        'name' => 'Constructora del Valle S.A.S.',
    ]))->assertCreated();

    $customer = Customer::query()->where('document_number', '900123456-7')->firstOrFail();

    expect($customer->document_type)->toBe(DocumentType::NIT);
});

it('rechaza un tipo de documento que no existe en el enum', function () {
    // El selector del front ofrecía "TI", que el backend nunca soportó.
    $this->postJson('/api/customers', customerPayload(['document_type' => 'TI']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_type']);
});

it('permite editar sin reenviar los datos de contacto', function () {
    $this->postJson('/api/customers', customerPayload())->assertCreated();
    $customer = Customer::query()->where('document_number', '1088001122')->firstOrFail();

    // Las fichas históricas se crearon sin estos datos: la edición no debe
    // exigirlos, o quedarían bloqueadas.
    $this->putJson('/api/customers/'.$customer->id, ['name' => 'Cliente Renombrado'])
        ->assertOk();

    expect($customer->fresh()->name)->toBe('Cliente Renombrado');
});
