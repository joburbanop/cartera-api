<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\Financial\Amortization\AmortizationService;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AmortizationController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AmortizationService $amortizationService
    ) {}

    public function generate(Contract $contract): JsonResponse
    {
        $plan = $this->amortizationService->generateInitialProjection($contract);

        return $this->successResponse(
            $plan,
            'Tabla de amortización generada exitosamente.',
            201
        );
    }

    public function show(Contract $contract, Request $request): JsonResponse
    {
        try {
            $plan = $contract->installments()->get();

            if ($plan->isEmpty()) {
                $plan = $this->amortizationService->generateInitialProjection($contract);
            }

            return $this->successResponse($plan, 'Plan de amortización obtenido exitosamente.');
        } catch (\Exception $e) {
            \Log::error('Error consultando amortización para contrato ' . $contract->id . ': ' . $e->getMessage());

            return response()->json([
                'error' => 'Falla interna al consultar el plan de amortización.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function downloadPdf(Contract $contract, Request $request): Response
    {
        $type = strtolower((string) $request->query('type', 'internal'));
        $plan = $contract->installments()->get();

        if ($plan->isEmpty()) {
            $plan = $this->amortizationService->generateInitialProjection($contract);
        }

        $customer = $contract->customer;
        $lot = $contract->lot;
        $project = $lot?->project;

        $pdf = Pdf::loadView('pdf.amortization', [
            'contract' => $contract,
            'customer' => $customer,
            'lot' => $lot,
            'project' => $project,
            'plan' => $plan,
            'version' => null,
            'type' => $type,
        ]);

        $label = $type === 'client' ? 'cliente' : 'interno';

        return $pdf->download(sprintf('extracto-amortizacion-%s-v1.pdf', $label));
    }
}
