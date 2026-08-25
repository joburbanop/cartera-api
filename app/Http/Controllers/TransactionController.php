<?php

namespace App\Http\Controllers;

use App\DTOs\CreateTransactionDTO;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Transaction;
use App\Services\Financial\Transaction\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionService $transactionService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Transaction::query()->with(['contract.customer', 'contract.lot']);

        if ($request->filled('customer_id')) {
            $query->whereHas('contract.customer', function ($customerQuery) use ($request) {
                $customerQuery->where('id', $request->integer('customer_id'));
            });
        }

        if ($request->filled('lot_id')) {
            $query->whereHas('contract.lot', function ($lotQuery) use ($request) {
                $lotQuery->where('id', $request->integer('lot_id'));
            });
        }

        $transactions = $query
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Transaction $transaction) {
                $receiptPath = $transaction->receipt?->file_path;

                return [
                    'id' => $transaction->id,
                    'transaction_type' => $transaction->transaction_type,
                    'amount' => $transaction->amount,
                    'payment_method' => $transaction->payment_method,
                    'transaction_date' => $transaction->transaction_date ? $transaction->transaction_date->format('Y-m-d') : null,
                    'created_at' => $transaction->created_at ? $transaction->created_at->format('Y-m-d H:i:s') : null,
                    'receipt' => $receiptPath ? Storage::disk('public')->url($receiptPath) : null,
                    'customer_name' => $transaction->contract?->customer?->name ?? 'Sin Cliente',
                    'lot_number' => $transaction->contract?->lot?->number ?? 'Sin Lote',
                ];
            });

        return response()->json([
            'data' => $transactions,
        ]);
    }

    public function indexByContract(int $contractId): JsonResponse
    {
        $transactions = Transaction::query()
            ->where('contract_id', $contractId)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Transaction $transaction) {
                $receiptPath = $transaction->receipt?->file_path;

                return [
                    'id' => $transaction->id,
                    'contract_id' => $transaction->contract_id,
                    'transaction_type' => $transaction->transaction_type,
                    'amount' => $transaction->amount,
                    'payment_method' => $transaction->payment_method,
                    'transaction_date' => $transaction->transaction_date?->format('Y-m-d'),
                    'created_at' => $transaction->created_at?->format('Y-m-d H:i:s'),
                    'receipt' => $receiptPath ? Storage::disk('public')->url($receiptPath) : null,
                ];
            });

        return response()->json([
            'data' => $transactions,
        ]);
    }

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
