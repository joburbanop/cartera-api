<?php

namespace App\Http\Controllers\Inventory; // <-- Nota el nuevo namespace

use App\Http\Controllers\Controller; // <-- Importante: Importar el controlador base
use App\Http\Requests\StoreProjectRequest;
use App\DTOs\CreateProjectDTO;
use App\Services\Inventory\ProjectService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\UpdateProjectRequest;
use App\DTOs\UpdateProjectDTO;
use App\Models\Project;
use DomainException;

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

    // Agrega este método a tu ProjectController
    public function index(): JsonResponse
    {
        // Llamamos al servicio para obtener los proyectos paginados
        $projects = $this->projectService->getAllProjects();

        return $this->successResponse($projects, 'Lista de proyectos obtenida exitosamente.');
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        try {
            $dto = UpdateProjectDTO::fromRequest($request);

            $userId = auth()->id() ?? 1;

            $project = $this->projectService->updateProject(
                $project,
                $dto,
                $userId
            );

            return $this->successResponse(
                $project,
                'Proyecto actualizado exitosamente.'
            );
        } catch (DomainException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422
            );
        }
    }

   public function archive(Project $project): JsonResponse
    {
        try {
            $userId = auth()->id() ?? 1;

            $project = $this->projectService->archiveProject(
                $project,
                $userId
            );

            return $this->successResponse(
                $project,
                'Proyecto archivado exitosamente.'
            );

        } catch (DomainException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422
            );
        }
    }
    public function activate(Project $project): JsonResponse
    {
        try {
            $userId = auth()->id() ?? 1;

            $project = $this->projectService->activateProject(
                $project,
                $userId
            );

            return $this->successResponse(
                $project,
                'Proyecto activado exitosamente.'
            );
        } catch (DomainException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422
            );
        }
    }
}