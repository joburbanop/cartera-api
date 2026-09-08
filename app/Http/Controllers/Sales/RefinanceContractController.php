<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\RefinanceContractRequest;
use App\Models\Contract;
use App\Services\Financial\Refinancing\RefinanceContractService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class RefinanceContractController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RefinanceContractService $refinanceContractService,
    ) {}

    public function store(RefinanceContractRequest $request, Contract $contract): JsonResponse
    {
        $applied = $this->refinanceContractService->apply($contract, $request->validated());

        return $this->successResponse(
            $contract->fresh(['installments', 'paymentPromises']),
            $applied
                ? 'Contrato refinanciado exitosamente.'
                : 'Esta refinanciación ya había sido aplicada; no se repitió el cambio.',
        );
    }
}
