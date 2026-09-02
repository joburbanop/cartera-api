<?php

use App\Enums\RoleName;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Financial\Amortization\AmortizationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;

uses(RefreshDatabase::class);

it('no expone el mensaje interno de una excepción al consultar amortización', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $project = Project::query()->create([
        'name' => 'Proyecto 500',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create(['project_id' => $project->id]);
    $contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'status' => 'activo',
    ]);
    $contract->installments()->delete();

    $this->mock(AmortizationService::class, function ($mock) {
        $mock->shouldReceive('generateInitialProjection')
            ->andThrow(new RuntimeException('SECRET_INTERNAL_STACK'));
    });

    Log::spy();

    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $response = $this->getJson("/api/contracts/{$contract->id}/amortization")
        ->assertStatus(500)
        ->assertJsonPath('message', 'Ocurrió un error al procesar la solicitud');

    expect($response->json())->not->toHaveKey('details');
    expect($response->getContent())->not->toContain('SECRET_INTERNAL_STACK');
});
