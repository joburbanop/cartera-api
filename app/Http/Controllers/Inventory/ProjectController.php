<?php

namespace App\Http\Controllers\Inventory; // <-- Nota el nuevo namespace

use App\Http\Controllers\Controller; // <-- Importante: Importar el controlador base
use App\Http\Requests\StoreProjectRequest;
use App\DTOs\CreateProjectDTO;
use App\Services\Inventory\ProjectService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProjectController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ProjectService $projectService
    ) {}

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $dto = CreateProjectDTO::fromRequest($request);
        
        $userId = auth()->id() ?? 1; // ID temporal de prueba

        $project = $this->projectService->createProject($dto, $userId);

        return $this->successResponse($project, 'Proyecto inmobiliario creado exitosamente.', 201);
    }
}