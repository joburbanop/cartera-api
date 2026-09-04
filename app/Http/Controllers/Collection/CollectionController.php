<?php

namespace App\Http\Controllers\Collection;

use App\DTOs\CascadePaymentDTO;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCascadePaymentRequest;
use App\Services\Collection\PreventaThenCascadeCollectionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class CollectionController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected PreventaThenCascadeCollectionService $preventaThenCascadeCollectionService,
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
}