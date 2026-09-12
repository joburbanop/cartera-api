<?php

namespace App\Http\Controllers;

use App\DTOs\CreateTransactionDTO;
use App\Enums\PaymentReversalReason;
use App\Http\Requests\ReversePaymentRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Contract;
use App\Models\Transaction;
use App\Services\Collection\PaymentReversalService;
use App\Services\Financial\Transaction\TransactionService;
use App\Support\ReceiptNumber;
use App\Support\SafeUploadedFileName;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TransactionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TransactionService $transactionService,
        private PaymentReversalService $paymentReversalService,
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
        $contract = Contract::query()->findOrFail($contractId);
        $lastEvent = $this->paymentReversalService->lastCollectionEvent($contract);
        $lastEventIds = $lastEvent->pluck('id')->map(fn ($id) => (int) $id)->all();
        $lastEventReversible = $this->paymentReversalService->eventWouldBeReversible($contract, $lastEvent);

        $paginator = Transaction::query()
            ->with(['receipt', 'allocations.installment'])
            ->where('contract_id', $contractId)
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        $paginator->getCollection()->transform(fn (Transaction $transaction) => [
            'id' => $transaction->id,
            'contract_id' => $transaction->contract_id,
            'transaction_type' => $transaction->transaction_type,
            'amount' => $transaction->amount,
            'payment_method' => $transaction->payment_method,
            'receipt_number' => ReceiptNumber::fromStored($transaction->receipt_number, $transaction->notes),
            'transaction_date' => $transaction->transaction_date?->format('Y-m-d'),
            'created_at' => $transaction->created_at?->format('Y-m-d H:i:s'),
            'receipt' => $transaction->receipt
                ? route('transactions.receipt', $transaction->id)
                : null,
            'allocations' => $this->presentAllocations($transaction),
            'reversed_at' => $transaction->reversed_at?->format('Y-m-d H:i:s'),
            'reversal_transaction_id' => $transaction->reversal_transaction_id,
            'reversal_reason' => $transaction->reversal_reason,
            'reversal_notes' => $transaction->reversal_notes,
            'can_reverse' => $lastEventReversible
                && in_array((int) $transaction->id, $lastEventIds, true),
        ]);

        return $this->successResponse($paginator, 'Transacciones del contrato obtenidas exitosamente.');
    }

    public function reverse(
        ReversePaymentRequest $request,
        int $contractId,
        int $transactionId,
    ): JsonResponse {
        $reason = PaymentReversalReason::from((string) $request->validated('reason'));
        $notes = $request->validated('notes');

        $result = $this->paymentReversalService->reverse(
            $contractId,
            $transactionId,
            $reason,
            is_string($notes) ? $notes : null,
            $request->user(),
        );

        return $this->successResponse($result, 'Pago revertido exitosamente.', 201);
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
            'receipt_number' => ReceiptNumber::fromStored($transaction->receipt_number, $transaction->notes),
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

    /**
     * Reparto de un pago que cubrió cuota inicial y cuota regular a la vez.
     * El total sigue siendo `amount`: esto solo cuenta a dónde fue cada peso.
     *
     * @return list<array<string, mixed>>
     */
    private function presentAllocations(Transaction $transaction): array
    {
        return $transaction->allocations
            ->sortBy('id')
            ->map(fn ($allocation) => [
                'target' => $allocation->target->value,
                'target_label' => $allocation->target->label(),
                'installment_number' => $allocation->installment
                    ? (int) $allocation->installment->installment_number
                    : null,
                'amount' => $allocation->amount,
                'principal' => $allocation->principal,
                'interest' => $allocation->interest,
            ])
            ->values()
            ->all();
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->integer('per_page', 20)));
    }
}
