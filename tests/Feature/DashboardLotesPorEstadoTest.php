<?php

use App\Enums\LotStatus;
use App\Enums\RoleName;
use App\Models\Lot;
use App\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->project = Project::query()->create([
        'name' => 'Proyecto Lotes Estado',
        'description' => 'Fixture lotes por estado',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
});

it('cuenta lotes agrupados por los valores reales de LotStatus', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    Lot::factory()->create(['project_id' => $this->project->id, 'number' => 'L-LE-01', 'status' => LotStatus::DISPONIBLE->value]);
    Lot::factory()->create(['project_id' => $this->project->id, 'number' => 'L-LE-02', 'status' => LotStatus::DISPONIBLE->value]);
    Lot::factory()->create(['project_id' => $this->project->id, 'number' => 'L-LE-03', 'status' => LotStatus::PREVENTA->value]);
    Lot::factory()->create(['project_id' => $this->project->id, 'number' => 'L-LE-04', 'status' => LotStatus::VENDIDO->value]);
    Lot::factory()->create(['project_id' => $this->project->id, 'number' => 'L-LE-05', 'status' => LotStatus::ABOGADO->value]);
    Lot::factory()->create(['project_id' => $this->project->id, 'number' => 'L-LE-06', 'status' => LotStatus::SEPARADO->value]);

    $this->getJson('/api/dashboard/lotes-por-estado')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.'.LotStatus::DISPONIBLE->value, 2)
        ->assertJsonPath('data.'.LotStatus::PREVENTA->value, 1)
        ->assertJsonPath('data.'.LotStatus::VENDIDO->value, 1)
        ->assertJsonPath('data.'.LotStatus::ABOGADO->value, 1)
        ->assertJsonPath('data.'.LotStatus::SEPARADO->value, 1);
});

it('permite a socio_gerencia consultar lotes por estado y niega a admin_sistema', function () {
    Lot::factory()->create([
        'project_id' => $this->project->id,
        'number' => 'L-LE-10',
        'status' => LotStatus::DISPONIBLE->value,
    ]);

    $this->actingAsRole(RoleName::SOCIO_GERENCIA->value);

    $this->getJson('/api/dashboard/lotes-por-estado')
        ->assertOk()
        ->assertJsonPath('data.'.LotStatus::DISPONIBLE->value, 1);

    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->getJson('/api/dashboard/lotes-por-estado')->assertForbidden();
});
