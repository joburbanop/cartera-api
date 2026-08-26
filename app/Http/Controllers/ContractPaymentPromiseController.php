<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentPromisesRequest;
use App\DTOs\ContractPaymentPromiseDTO;
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
        $contract = \App\Models\Contract::with('paymentPromises')->findOrFail($contractId);

        return $this->successResponse(
            $contract->paymentPromises()->orderBy('expected_date')->get(),
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
}
