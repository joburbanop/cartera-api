<?php

namespace App\Http\Controllers\Collection;

use App\DTOs\CascadePaymentDTO;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCascadePaymentRequest;
use App\Http\Requests\StoreSplitPaymentRequest;
use App\Services\Collection\PreventaThenCascadeCollectionService;
use App\Services\Collection\SplitPaymentService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class CollectionController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected PreventaThenCascadeCollectionService $preventaThenCascadeCollectionService,
        protected SplitPaymentService $splitPaymentService,
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
        );

        return $this->successResponse(
            $result,
            'Pago dividido registrado exitosamente.',
            201,
        );
    }
}