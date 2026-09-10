<?php

namespace App\Http\Controllers\Collection;

use App\DTOs\CascadePaymentDTO;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCascadePaymentRequest;
use App\Http\Requests\StoreResidualCollectionRequest;
use App\Http\Requests\StoreSplitPaymentRequest;
use App\Services\Collection\PreventaThenCascadeCollectionService;
use App\Services\Collection\SplitPaymentService;
use App\Services\Residual\ResidualCollectionService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class CollectionController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected PreventaThenCascadeCollectionService $preventaThenCascadeCollectionService,
        protected SplitPaymentService $splitPaymentService,
        protected ResidualCollectionService $residualCollectionService,
    ) {}

    public function store(StoreCascadePaymentRequest $request): JsonResponse
    {
        $dto = CascadePaymentDTO::fromRequest($request);
        $paymentMethod = PaymentMethod::tryFrom((string) $request->input('payment_method', ''));

        $result = $this->preventaThenCascadeCollectionService->process(
            $dto->contractId,
            $dto->amount,
            $dto->paymentOption,
            $dto->transactionDate,
            $dto->selectedInstallments,
            $dto->receipt,
            $paymentMethod,
            receiptNumber: $request->validated('receipt_number'),
        );

        return $this->successResponse(
            $result,
            'Recaudo en cascada registrado exitosamente.',
            201,
        );
    }

    /**
     * Un solo movimiento bancario repartido entre la cuota inicial y las
     * cuotas regulares.
     */
    public function storeSplit(StoreSplitPaymentRequest $request): JsonResponse
    {
        $rawDate = $request->input('payment_date', $request->input('transaction_date'));

        $result = $this->splitPaymentService->process(
            contractId: (int) $request->validated('contract_id'),
            toDownPayment: (string) $request->validated('to_down_payment'),
            toInstallments: (string) $request->validated('to_installments'),
            transactionDate: $rawDate ? Carbon::parse($rawDate) : null,
            selectedInstallmentIds: array_values(array_map(
                'intval',
                (array) $request->validated('selected_installments', [])
            )),
            receipt: $request->file('receipt'),
            paymentMethod: PaymentMethod::tryFrom((string) $request->input('payment_method', '')),
            notes: $request->validated('notes'),
            paymentOption: $request->validated('payment_option'),
            receiptNumber: $request->validated('receipt_number'),
        );

        return $this->successResponse(
            $result,
            'Pago dividido registrado exitosamente.',
            201,
        );
    }

    /**
     * Cobra residuales menores acumulados. Fuera del plan: no toca cuotas.
     */
    public function storeResidual(StoreResidualCollectionRequest $request): JsonResponse
    {
        $rawDate = $request->input('payment_date', $request->input('transaction_date'));

        $receipt = $request->file('receipt');
        if (! $receipt instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'receipt' => ResidualCollectionService::RECEIPT_REQUIRED,
            ]);
        }

        $result = $this->residualCollectionService->collect(
            contractId: (int) $request->validated('contract_id'),
            amount: (string) $request->validated('amount'),
            receipt: $receipt,
            transactionDate: $rawDate ? Carbon::parse($rawDate) : null,
            paymentMethod: PaymentMethod::tryFrom((string) $request->input('payment_method', '')),
            notes: $request->validated('notes'),
            receiptNumber: $request->validated('receipt_number'),
        );

        return $this->successResponse(
            $result,
            'Cobro de residuales registrado exitosamente.',
            201,
        );
    }
}
