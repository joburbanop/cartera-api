<?php

namespace App\Http\Controllers\Sales;

use App\Enums\AmortizationStatus;
use App\Http\Controllers\Controller;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Services\Financial\Amortization\AmortizationService;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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

    public function updateInstallmentDueDate(
        Contract $contract,
        AmortizationInstallment $installment,
        Request $request,
    ): JsonResponse {
        if ((int) $installment->contract_id !== (int) $contract->id) {
            abort(404);
        }

        if ((int) $installment->installment_number <= 0) {
            throw ValidationException::withMessages([
                'installment' => 'La cuota inicial no se puede modificar desde este flujo.',
            ]);
        }

        $status = $installment->status instanceof AmortizationStatus
            ? $installment->status->value
            : (string) $installment->status;

        if ($status === AmortizationStatus::PAID->value) {
            throw ValidationException::withMessages([
                'installment' => 'No se puede modificar la fecha de una cuota ya pagada.',
            ]);
        }

        $validator = Validator::make($request->all(), [
            'due_date' => ['required', 'date'],
        ]);
        $validated = $validator->validate();

        $newDueDate = Carbon::parse((string) $validated['due_date'])->startOfDay();

        // TODO(bitácora): registrar aquí quién cambió la fecha, cuándo, y el valor anterior/nuevo,
        // cuando se construya el sistema de auditoría (Entrega 2).
        $installment->update([
            'due_date' => $newDueDate->toDateString(),
        ]);

        return $this->successResponse(
            $installment->fresh(),
            'Fecha de vencimiento actualizada exitosamente.'
        );
    }
}
