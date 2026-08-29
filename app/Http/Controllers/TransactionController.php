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
                return [
                    'id' => $transaction->id,
                    'transaction_type' => $transaction->transaction_type,
                    'amount' => $transaction->amount,
                    'payment_method' => $transaction->payment_method,
                    'transaction_date' => $transaction->transaction_date
                        ? $transaction->transaction_date->format('Y-m-d')
                        : null,
                    'created_at' => $transaction->created_at
                        ? $transaction->created_at->format('Y-m-d H:i:s')
                        : null,
                    'customer_name' => $transaction->contract?->customer?->name ?? 'Sin Cliente',
                    'lot_number' => $transaction->contract?->lot?->number ?? 'Sin Lote',
                    'receipt' => $transaction->receipt
                        ? route('transactions.receipt', $transaction->id)
                        : null,
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
                return [
                    'id' => $transaction->id,
                    'contract_id' => $transaction->contract_id,
                    'transaction_type' => $transaction->transaction_type,
                    'amount' => $transaction->amount,
                    'payment_method' => $transaction->payment_method,
                    'transaction_date' => $transaction->transaction_date?->format('Y-m-d'),
                    'created_at' => $transaction->created_at?->format('Y-m-d H:i:s'),
                    'receipt' => $transaction->receipt
                        ? route('transactions.receipt', $transaction->id)
                        : null,
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

    public function receipt(Transaction $transaction)
    {
        $receipt = $transaction->receipt;

        if (! $receipt) {
            return response()->json([
                'message' => 'Esta transacción no tiene recibo.',
            ], 404);
        }

        if (! Storage::disk('local')->exists($receipt->file_path)) {
            return response()->json([
                'message' => 'El archivo del recibo no existe.',
            ], 404);
        }

        return Storage::disk('local')->response(
            $receipt->file_path,
            $receipt->file_name,
            [
                'Content-Type' => $receipt->file_type,
                'Content-Disposition' => 'inline; filename="' . $receipt->file_name . '"',
            ]
        );
    }
}
