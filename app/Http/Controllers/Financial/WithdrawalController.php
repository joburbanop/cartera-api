<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\CreateWithdrawalDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWithdrawalRequest;
use App\Models\Contract;
use App\Services\Financial\Withdrawal\WithdrawalService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class WithdrawalController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected WithdrawalService $withdrawalService
    ) {}

    public function store(StoreWithdrawalRequest $request): JsonResponse
    {
        $dto = CreateWithdrawalDTO::fromRequest($request);

        $contract = Contract::findOrFail($dto->contractId);

        $withdrawal = $this->withdrawalService->createPreventa(
            contract: $contract,
            cause: $dto->cause,
            requestDate: Carbon::parse($dto->requestDate),
            retentionPercentage: $dto->retentionPercentage,
            observations: $dto->observations,
            modificationJustification: $dto->modificationJustification,
            createdBy: $request->user()?->id,
        );

        return $this->successResponse(
            $withdrawal,
            'Desistimiento registrado exitosamente.',
            201
        );
    }
    public function calculatePreventa(StoreWithdrawalRequest $request): JsonResponse
        {
            $dto = CreateWithdrawalDTO::fromRequest($request);
            $contract = Contract::findOrFail($dto->contractId);

            $calculation = $this->withdrawalService->calculatePreventa(
                contract: $contract,
                cause: $dto->cause,
                requestDate: Carbon::parse($dto->requestDate),
                retentionPercentage: $dto->retentionPercentage,
            );

            return $this->successResponse(
                $calculation,
                'Liquidación calculada exitosamente.'
            );
        }
}