<?php

use App\Enums\RoleName;
use App\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('cuenta proyectos activos con agregación SQL', function () {
    Project::query()->create([
        'name' => 'Activo 1',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    Project::query()->create([
        'name' => 'Activo 2',
        'location' => 'Medellín',
        'status' => 'activo',
    ]);
    Project::query()->create([
        'name' => 'Cerrado',
        'location' => 'Cali',
        'status' => 'cerrado',
    ]);

    $this->actingAsRole(RoleName::SOCIO_GERENCIA->value);

    $this->getJson('/api/dashboard/proyectos-activos')
        ->assertOk()
        ->assertJsonPath('data.total_proyectos_activos', 2);
});

it('niega proyectos activos a admin_sistema', function () {
    $this->actingAsRole(RoleName::ADMIN_SISTEMA->value);

    $this->getJson('/api/dashboard/proyectos-activos')->assertForbidden();
});
