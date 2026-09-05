<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Services\Financial\Amortization\AdjustInstallmentDueDatesService;
use App\Services\Financial\Amortization\AmortizationService;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class AmortizationController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AmortizationService $amortizationService,
        protected AdjustInstallmentDueDatesService $adjustInstallmentDueDatesService,
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
            Log::error('Error consultando amortización para contrato '.$contract->id.': '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Ocurrió un error al procesar la solicitud',
                'errors' => null,
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

        $contract->loadMissing(['customer', 'customers', 'lot.project']);
        $customer = $contract->primaryCustomer();
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

    public function previewInstallmentDueDate(
        Contract $contract,
        AmortizationInstallment $installment,
        Request $request,
    ): JsonResponse {
        $this->assertInstallmentBelongsToContract($contract, $installment);

        $validated = Validator::make($request->all(), [
            'due_date' => ['required', 'date'],
            'mode' => ['required', 'in:single,cascade'],
            'cadence' => ['nullable', 'in:same_day,month_end'],
        ])->validate();

        $plan = $this->adjustInstallmentDueDatesService->preview(
            $contract,
            $installment,
            (string) $validated['due_date'],
            (string) $validated['mode'],
            (string) ($validated['cadence'] ?? AdjustInstallmentDueDatesService::CADENCE_SAME_DAY),
        );

        return $this->successResponse($plan, 'Previsualización de vencimientos generada.');
    }

    public function updateInstallmentDueDate(
        Contract $contract,
        AmortizationInstallment $installment,
        Request $request,
    ): JsonResponse {
        $this->assertInstallmentBelongsToContract($contract, $installment);

        $validated = Validator::make($request->all(), [
            'due_date' => ['required', 'date'],
            'mode' => ['required', 'in:single,cascade'],
            'cadence' => ['nullable', 'in:same_day,month_end'],
            'confirm' => ['required', 'accepted'],
        ])->validate();

        $plan = $this->adjustInstallmentDueDatesService->apply(
            $contract,
            $installment,
            (string) $validated['due_date'],
            (string) $validated['mode'],
            (string) ($validated['cadence'] ?? AdjustInstallmentDueDatesService::CADENCE_SAME_DAY),
        );

        return $this->successResponse(
            [
                'plan' => $plan,
                'installment' => $installment->fresh(),
                'contract' => $contract->fresh(),
            ],
            'Fechas de vencimiento actualizadas exitosamente.'
        );
    }

    public function updateInstallmentPaymentDate(
        Contract $contract,
        AmortizationInstallment $installment,
        Request $request,
    ): JsonResponse {
        $this->assertInstallmentBelongsToContract($contract, $installment);

        $validated = Validator::make($request->all(), [
            'payment_date' => ['required', 'date'],
        ])->validate();

        $previous = $installment->payment_date
            ? Carbon::parse((string) $installment->payment_date)->toDateString()
            : null;
        $newDate = Carbon::parse((string) $validated['payment_date'])->toDateString();

        $installment->update([
            'payment_date' => $newDate,
        ]);

        $hasReceipt = filled($installment->receipt_number);
        $hasTransactions = $contract->transactions()->exists();
        $warning = null;

        if ($hasReceipt || $hasTransactions) {
            $warning = 'Esta fecha quedará distinta a la de la transacción o recibo original';
        }

        $activity = activity()
            ->performedOn($contract)
            ->withProperties([
                'installment_number' => (int) $installment->installment_number,
                'before' => ['payment_date' => $previous],
                'after' => ['payment_date' => $newDate],
            ]);

        if (auth()->user()) {
            $activity->causedBy(auth()->user());
        }

        $activity->log(sprintf(
            'Cambió la fecha de pago de la cuota %d de %s a %s',
            (int) $installment->installment_number,
            $previous ? Carbon::parse($previous)->format('d/m/Y') : '--',
            Carbon::parse($newDate)->format('d/m/Y'),
        ));

        return $this->successResponse(
            [
                'installment' => $installment->fresh(),
                'warning' => $warning,
            ],
            'Fecha de pago actualizada exitosamente.'
        );
    }

    private function assertInstallmentBelongsToContract(
        Contract $contract,
        AmortizationInstallment $installment,
    ): void {
        if ((int) $installment->contract_id !== (int) $contract->id) {
            abort(404);
        }
    }
}
