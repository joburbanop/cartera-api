<?php

namespace App\Http\Controllers;

use App\DTOs\CreateTransactionDTO;
use App\Http\Requests\StoreTransactionRequest;
use App\Services\Financial\Transaction\TransactionService;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionService $transactionService
    ) {}

    public function store(
        StoreTransactionRequest $request,
        int $contractId
    ): JsonResponse {
        $dto = CreateTransactionDTO::fromRequest(
            $request,
            $contractId
        );

        $transaction = $this->transactionService
            ->register($dto);

        return response()->json([
            'message' => 'Abono de cuota inicial registrado correctamente.',
            'data' => $transaction,
        ], 201);
    }
}
