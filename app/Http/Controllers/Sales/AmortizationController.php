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
        $paymentPlan = $this->amortizationService->generateVersionOne($contract);

        return $this->successResponse(
            $paymentPlan,
            'Tabla de amortización (Versión 1) generada y guardada bajo llave exitosamente.',
            201
        );
    }

    public function show(Contract $contract, Request $request): JsonResponse
    {
        $version = $request->query('version');
        $plan = $this->amortizationService->getPlanByContract($contract, $version !== null ? (int) $version : null);

        if ($plan->isEmpty()) {
            $generatedPlan = $this->amortizationService->generateVersionOne($contract);

            return $this->successResponse(
                $generatedPlan,
                'Plan de amortización generado automáticamente al ingresar al contrato.',
                200
            );
        }

        $versions = $this->amortizationService->getAvailableVersions($contract);
        $activeVersion = $version !== null ? (int) $version : (count($versions) > 0 ? (int) end($versions) : 1);

        $payload = [
            'version' => $activeVersion,
            'versions' => $versions,
            'rows' => $plan,
        ];

        return $this->successResponse($payload, 'Plan de amortización obtenido exitosamente.');
    }

    public function downloadPdf(Contract $contract, Request $request): Response
    {
        $version = $request->query('version');
        $type = strtolower((string) $request->query('type', 'internal'));
        $selectedVersion = $version !== null ? (int) $version : max($this->amortizationService->getAvailableVersions($contract));
        $plan = $this->amortizationService->getPlanByContract($contract, $selectedVersion);

        if ($plan->isEmpty()) {
            $plan = $this->amortizationService->getPlanByContract($contract);
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
            'version' => $selectedVersion,
            'type' => $type,
        ]);

        $label = $type === 'client' ? 'cliente' : 'interno';

        return $pdf->download(sprintf('extracto-amortizacion-%s-v%s.pdf', $label, $selectedVersion));
    }
}
