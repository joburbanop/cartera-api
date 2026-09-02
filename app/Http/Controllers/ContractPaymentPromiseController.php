<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderPaymentPromisesRequest;
use App\Http\Requests\StorePaymentPromisesRequest;
use App\DTOs\ContractPaymentPromiseDTO;
use App\Models\Contract;
use App\Services\ContractPaymentPromiseService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class ContractPaymentPromiseController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ContractPaymentPromiseService $contractPaymentPromiseService,
    ) {}

    public function index($contractId): JsonResponse
    {
        return $this->successResponse(
            $this->contractPaymentPromiseService->listWithStatus((int) $contractId),
            'Plan comercial de pagos obtenido exitosamente.'
        );
    }

    public function store(StorePaymentPromisesRequest $request, $contractId): JsonResponse
    {
        $promises = array_map(
            fn (array $promise) => ContractPaymentPromiseDTO::fromRequest($promise),
            $request->input('promises', [])
        );

        $savedPromises = $this->contractPaymentPromiseService->storeCommercialPlan((int) $contractId, $promises);

        return $this->successResponse(
            $savedPromises,
            'Plan comercial de pagos guardado exitosamente.',
            201
        );
    }

    public function reorder(ReorderPaymentPromisesRequest $request, Contract $contract): JsonResponse
    {
        $updated = $this->contractPaymentPromiseService->reorder(
            $contract->id,
            $request->validated('promises'),
        );

        return $this->successResponse(
            $updated,
            'Cronograma reordenado exitosamente.',
        );
    }
}
