<?php

namespace App\Http\Controllers\Collection;

use App\DTOs\CascadePaymentDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCascadePaymentRequest;
use App\Services\Collection\CascadeCollectionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class CollectionController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected CascadeCollectionService $cascadeCollectionService,
    ) {}

    public function store(StoreCascadePaymentRequest $request): JsonResponse
    {
        $dto = CascadePaymentDTO::fromRequest($request);

        $result = $this->cascadeCollectionService->process(
            $dto->contractId,
            $dto->amount,
            $dto->paymentOption,
        );

        return $this->successResponse(
            $result,
            'Recaudo en cascada registrado exitosamente.',
            201,
        );
    }
}
