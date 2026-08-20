<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLotRequest;
use App\DTOs\CreateLotDTO;
use App\Services\Inventory\LotService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

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
}