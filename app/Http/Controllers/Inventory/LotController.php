<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLotRequest;
use App\DTOs\CreateLotDTO;
use App\Models\Lot;
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
        $projectId = $request->query('project_id') ? (int) $request->query('project_id') : null;
        $perPage = min(100, max(1, (int) $request->integer('per_page', 20)));
        $lots = $this->lotService->getAllLots($projectId, $perPage);

        return $this->successResponse($lots, 'Lista de lotes obtenida exitosamente.');
    }

    public function show(Lot $lot): JsonResponse
    {
        return $this->successResponse(
            $lot->load('project')->loadCount('contracts')->load(['contracts' => function ($relation): void {
                $relation->select('id', 'lot_id')->orderByDesc('id');
            }]),
            'Lote obtenido exitosamente.',
        );
    }
}