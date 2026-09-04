<?php

namespace App\Http\Controllers;

use App\DTOs\CreateTransactionDTO;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Transaction;
use App\Services\Financial\Transaction\TransactionService;
use App\Support\SafeUploadedFileName;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TransactionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TransactionService $transactionService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Transaction::query()->with(['contract.customer', 'contract.customers', 'contract.lot', 'receipt']);

        if ($request->filled('customer_id')) {
            $customerId = $request->integer('customer_id');
            $query->where(function ($transactionQuery) use ($customerId) {
                $transactionQuery
                    ->whereHas('contract.customer', function ($customerQuery) use ($customerId) {
                        $customerQuery->where('customers.id', $customerId);
                    })
                    ->orWhereHas('contract.customers', function ($holdersQuery) use ($customerId) {
                        $holdersQuery->where('customers.id', $customerId);
                    });
            });
        }

        if ($request->filled('lot_id')) {
            $query->whereHas('contract.lot', function ($lotQuery) use ($request) {
                $lotQuery->where('id', $request->integer('lot_id'));
            });
        }

        $paginator = $query
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        $paginator->getCollection()->transform(fn (Transaction $transaction) => $this->presentTransaction($transaction));

        return $this->successResponse($paginator, 'Lista de transacciones obtenida exitosamente.');
    }

    public function indexByContract(Request $request, int $contractId): JsonResponse
    {
        $paginator = Transaction::query()
            ->with('receipt')
            ->where('contract_id', $contractId)
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        $paginator->getCollection()->transform(fn (Transaction $transaction) => [
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
        ]);

        return $this->successResponse($paginator, 'Transacciones del contrato obtenidas exitosamente.');
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

        $downloadName = SafeUploadedFileName::forContentDisposition($receipt->file_name);

        return Storage::disk('local')->response(
            $receipt->file_path,
            $downloadName,
            [
                'Content-Type' => $receipt->file_type,
                'Content-Disposition' => 'inline; filename="'.$downloadName.'"',
            ]
        );
    }

    private function presentTransaction(Transaction $transaction): array
    {
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
            'customer_name' => $transaction->contract?->holderDisplayName() ?? 'Sin Cliente',
            'lot_number' => $transaction->contract?->lot?->number ?? 'Sin Lote',
            'receipt' => $transaction->receipt
                ? route('transactions.receipt', $transaction->id)
                : null,
        ];
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->integer('per_page', 20)));
    }
}
