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
}