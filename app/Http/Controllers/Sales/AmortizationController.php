<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\Financial\AmortizationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class AmortizationController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AmortizationService $amortizationService
    ) {}

    public function generate(Contract $contract): JsonResponse
    {
        $paymentPlan = $this->amortizationService->generateVersionOne($contract);

        return $this->successResponse(
            $paymentPlan, 
            'Tabla de amortización (Versión 1) generada y guardada bajo llave exitosamente.', 
            201
        );
    }

    public function show(Contract $contract): JsonResponse
    {
        $plan = $this->amortizationService->getPlanByContract($contract);

        if ($plan->isEmpty()) {
            $generatedPlan = $this->amortizationService->generateVersionOne($contract);

            return $this->successResponse(
                $generatedPlan,
                'Plan de amortización generado automáticamente al ingresar al contrato.',
                200
            );
        }

        return $this->successResponse($plan, 'Plan de amortización obtenido exitosamente.');
    }
}