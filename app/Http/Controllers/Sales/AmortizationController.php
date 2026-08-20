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
}