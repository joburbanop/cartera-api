<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLotRequest;
use App\Http\Requests\UpdateLotRequest;
use App\DTOs\CreateLotDTO;
use App\DTOs\UpdateLotDTO;
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
        $userId = auth()->id() ?? 1;

        $lot = $this->lotService->createLot($dto, $userId);

        return $this->successResponse(
            $lot->load('project'),
            'Lote registrado exitosamente.',
            201
        );
    }

    public function index(Request $request): JsonResponse
    {
        $projectId = $request->query('project_id')
            ? (int) $request->query('project_id')
            : null;

        $perPage = min(
            100,
            max(1, (int) $request->integer('per_page', 20))
        );

        $lots = $this->lotService->getAllLots(
            $projectId,
            $perPage,
            [
                'project_id' => $projectId,
                'number' => $request->query('number'),
                'status' => $request->query('status'),
                'plan_type' => $request->query('plan_type'),
                'cartera' => $request->query('cartera'),
                'customer' => $request->query('customer'),
            ]
        );

        return $this->successResponse(
            $lots,
            'Lista de lotes obtenida exitosamente.'
        );
    }

    public function show(Lot $lot): JsonResponse
    {
        return $this->successResponse(
            $lot
                ->load('project')
                ->loadCount('contracts')
                ->load([
                    'contracts' => function ($relation): void {
                        $relation
                            ->select('id', 'lot_id')
                            ->orderByDesc('id');
                    }
                ]),
            'Lote obtenido exitosamente.'
        );
    }

    public function update(
        UpdateLotRequest $request,
        Lot $lot
    ): JsonResponse {
        $dto = UpdateLotDTO::fromRequest($request);
        $userId = auth()->id() ?? 1;

        $lot = $this->lotService->updateLot(
            $lot,
            $dto,
            $userId
        );

        return $this->successResponse(
            $lot,
            'Lote actualizado exitosamente.'
        );
    }

    public function archive(Lot $lot): JsonResponse
    {
        $userId = auth()->id() ?? 1;

        $this->lotService->archiveLot(
            $lot,
            $userId
        );

        return $this->successResponse(
            null,
            'Lote archivado exitosamente.'
        );
    }

    public function activate(int $lot): JsonResponse
    {
        $lotModel = Lot::withTrashed()->findOrFail($lot);

        $userId = auth()->id() ?? 1;

        $lot = $this->lotService->activateLot(
            $lotModel,
            $userId
        );

        return $this->successResponse(
            $lot,
            'Lote reactivado exitosamente.'
        );
    }

    public function archived(Request $request): JsonResponse
    {
        $projectId = $request->query('project_id')
            ? (int) $request->query('project_id')
            : null;

        $lots = $this->lotService->getArchivedLots($projectId);

        return $this->successResponse(
            $lots,
            'Lista de lotes archivados obtenida exitosamente.'
        );
    }
}