<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::query()->firstOrFail();

        $projects = [
            [
                'name' => 'Bosque Real',
                'description' => 'Proyecto residencial con zonas verdes y urbanismo moderno.',
                'location' => 'Calle 10 # 25-30, Envigado',
                'status' => 'active',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ],
            [
                'name' => 'Andes Heights',
                'description' => 'Urbanización de estrato medio-alto con vista panorámica.',
                'location' => 'Autopista Sur, Medellín',
                'status' => 'active',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ],
            [
                'name' => 'Llanos del Sol',
                'description' => 'Proyecto en etapa de cierre y entrega de lotes.',
                'location' => 'Vereda La Florida, Rionegro',
                'status' => 'completed',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ],
        ];

        foreach ($projects as $project) {
            Project::query()->firstOrCreate(
                ['name' => $project['name']],
                $project
            );
        }
    }
}
