<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLotRequest;
use App\DTOs\CreateLotDTO;
use App\Services\Inventory\LotService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LotController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected LotService $lotService
    ) {}

    public function store(StoreLotRequest $request): JsonResponse
    {
        $dto = CreateLotDTO::fromRequest($request);
        $userId = auth()->id() ?? 1; // ID temporal hasta implementar JWT/Sanctum

        $lot = $this->lotService->createLot($dto, $userId);

        return $this->successResponse($lot->load('project'), 'Lote registrado exitosamente.', 201);
    }

    public function index(Request $request): JsonResponse
    {
        // Capturamos el project_id de la URL si existe (ej: /api/lots?project_id=1)
        $projectId = $request->query('project_id') ? (int) $request->query('project_id') : null;

        // Llamamos al servicio
        $lots = $this->lotService->getAllLots($projectId);

        return $this->successResponse($lots, 'Lista de lotes obtenida exitosamente.');
    }
}