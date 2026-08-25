<?php

namespace Database\Seeders;

use App\Enums\LotStatus;
use App\Enums\LotType;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class LotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::query()->firstOrFail();
        $projects = Project::query()->get();

        foreach ($projects as $project) {
            $lots = [
                [
                    'number' => 'A-01',
                    'area_m2' => 180,
                    'price_m2' => 1200000,
                    'list_price' => 216000000,
                    'status' => LotStatus::DISPONIBLE->value,
                    'type' => LotType::RESIDENTIAL->value,
                    'folio_matricula' => 'FM-' . strtoupper(substr($project->name, 0, 3)) . '-001',
                    'ficha_catastral' => 'FIC-' . $project->id . '-001',
                    'boundaries_north' => 'Vía principal',
                    'boundaries_south' => 'Lote B-02',
                    'boundaries_east' => 'Zona verde',
                    'boundaries_west' => 'Calle de acceso',
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ],
                [
                    'number' => 'A-02',
                    'area_m2' => 200,
                    'price_m2' => 1250000,
                    'list_price' => 250000000,
                    'status' => LotStatus::PREVENTA->value,
                    'type' => LotType::RESIDENTIAL->value,
                    'folio_matricula' => 'FM-' . strtoupper(substr($project->name, 0, 3)) . '-002',
                    'ficha_catastral' => 'FIC-' . $project->id . '-002',
                    'boundaries_north' => 'Lote A-01',
                    'boundaries_south' => 'Área común',
                    'boundaries_east' => 'Borde de parque',
                    'boundaries_west' => 'Carrera principal',
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ],
                [
                    'number' => 'A-03',
                    'area_m2' => 220,
                    'price_m2' => 1350000,
                    'list_price' => 297000000,
                    'status' => LotStatus::VENDIDO->value,
                    'type' => LotType::RESIDENTIAL->value,
                    'folio_matricula' => 'FM-' . strtoupper(substr($project->name, 0, 3)) . '-003',
                    'ficha_catastral' => 'FIC-' . $project->id . '-003',
                    'boundaries_north' => 'Lote A-02',
                    'boundaries_south' => 'Lote A-04',
                    'boundaries_east' => 'Patio communal',
                    'boundaries_west' => 'Vía de servicio',
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ],
            ];

            foreach ($lots as $lot) {
                Lot::query()->firstOrCreate(
                    ['project_id' => $project->id, 'number' => $lot['number']],
                    $lot
                );
            }
        }
    }
}
