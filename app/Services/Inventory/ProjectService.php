<?php

namespace App\Services\Inventory;

use App\DTOs\CreateProjectDTO;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    public function createProject(CreateProjectDTO $dto, int $userId): Project
    {
        return DB::transaction(function () use ($dto, $userId) {
            // 1. Crear el proyecto general
            $project = Project::create([
                'name' => $dto->name,
                'location' => $dto->location,
                'description' => $dto->description,
                'created_by' => $userId,
            ]);

            // 2. Enlazar las cuentas bancarias usando la tabla pivote (N:M)
            $project->bankAccounts()->attach($dto->bankAccountIds);

            // 3. Retornar el proyecto con sus cuentas cargadas para la respuesta
            return $project->load('bankAccounts');
        });
    }


    public function getAllProjects(int $perPage = 15)
    {
        // 1. Traemos los proyectos y delegamos a la Base de Datos el conteo de lotes
        $projects = Project::with(['bankAccounts', 'creator'])
            ->withCount([
                'lots as total_lots_count', // Cuenta TODOS los lotes asociados al proyecto
                
                'lots as available_lots_count' => function ($query) {
                    $query->where('status', 'disponible'); // Cuenta solo los disponibles
                },
                
                'lots as sold_lots_count' => function ($query) {
                    $query->whereIn('status', ['preventa', 'vendido']); // Cuenta los vendidos o separados
                }
            ])
            ->latest()
            ->paginate($perPage);

        // 2. Transformamos la colección paginada para inyectar los cálculos finales
        $projects->getCollection()->transform(function ($project) {
            
            $porcentaje = 0;
            
            if ($project->total_lots_count > 0) {
                // LÓGICA A PRUEBA DE BALAS: 
                // Lo vendido/separado es la resta del Total menos los Disponibles.
                // Así no importa si el estado dice "reserved", "vendido" o "separado".
                $lotesNoDisponibles = $project->total_lots_count - $project->available_lots_count;
                
                $porcentaje = ($lotesNoDisponibles / $project->total_lots_count) * 100;
            }

            // Inyectamos las propiedades amigables para Angular
            $project->avance_ventas = round($porcentaje);
            
            return $project;
        });

        return $projects;
    }
}