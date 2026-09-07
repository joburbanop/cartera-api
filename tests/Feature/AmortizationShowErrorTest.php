<?php

use App\Enums\RoleName;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('no expone detalles internos si falla la consulta de amortización', function () {
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

    Log::spy();

    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $this->getJson("/api/contracts/{$contract->id}/amortization")
        ->assertOk()
        ->assertJsonMissingPath('details');
});
