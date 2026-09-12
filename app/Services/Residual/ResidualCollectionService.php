<?php

namespace App\Services\Residual;

use App\Enums\PaymentMethod;
use App\Enums\ResidualBalanceStatus;
use App\Enums\TransactionType;
use App\Models\ContractResidualBalance;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Support\ContractCollectionGuard;
use App\Support\ContractFinancialLock;
use App\Support\ReceiptNumber;
use App\Support\SafeUploadedFileName;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cobra el SUM de residuales pendientes como ítem aparte.
 * No toca cuotas, allocator ni transaction_allocations.
 */
class ResidualCollectionService
{
    public const AMOUNT_EXCEEDS_PENDING = 'El monto supera el residual pendiente.';

    public const RECEIPT_REQUIRED = 'El comprobante es obligatorio.';

    public function __construct(
        private readonly ResidualBalanceService $residualBalanceService,
    ) {}

    /**
     * @return array{
     *     transaction_id: int,
     *     amount: string,
     *     pending_residual_balance: string,
     *     residual_balance_collectible: bool,
     *     residual_collectible_threshold: string,
     *     collected_row_ids: list<int>,
     *     partial_row_id: int|null
     * }
     */
    public function collect(
        int $contractId,
        string $amount,
        UploadedFile $receipt,
        ?Carbon $transactionDate = null,
        ?PaymentMethod $paymentMethod = null,
        ?string $notes = null,
        ?string $receiptNumber = null,
        ?int $bankAccountId = null,
    ): array {
        return DB::transaction(function () use ($contractId, $amount, $receipt, $transactionDate, $paymentMethod, $notes, $receiptNumber, $bankAccountId) {
            $contract = ContractFinancialLock::acquire($contractId);
            ContractCollectionGuard::assertAcceptsPayments($contract);
            ReceiptNumber::assertUnusedOnContract($contract->id, $receiptNumber);
            $toCollect = $this->money($amount);
            $pending = $this->residualBalanceService->pendingSum($contract->id);

            if (bccomp($pending, '0.00', 2) <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'No hay residuales pendientes de cobro.',
                ]);
            }

            if (! $this->residualBalanceService->isCollectible($contract->id, $pending)) {
                throw ValidationException::withMessages([
                    'amount' => 'El cobro como ítem aparte se habilita al acumular $'
                        .ResidualBalanceService::COLLECTIBLE_THRESHOLD.'.',
                ]);
            }

            if (bccomp($toCollect, $pending, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => self::AMOUNT_EXCEEDS_PENDING.' Pendiente: $'.$pending.'.',
                ]);
            }

            $date = ($transactionDate ?? Carbon::now())->copy()->startOfDay();
            $normalizedReceipt = ReceiptNumber::normalize($receiptNumber);
            $transaction = Transaction::query()->create([
                'contract_id' => $contract->id,
                'transaction_type' => TransactionType::RESIDUAL_COLLECTION,
                'amount' => $toCollect,
                'transaction_date' => $date->toDateString(),
                'payment_method' => $paymentMethod ?? PaymentMethod::CASH,
                'bank_account_id' => $bankAccountId,
                'notes' => ReceiptNumber::mergeIntoNotes(
                    $notes ?: 'Cobro de residuales menores acumulados',
                    $normalizedReceipt,
                ),
                'receipt_number' => $normalizedReceipt,
            ]);

            $path = $receipt->store('receipts', 'local');
            Receipt::query()->create([
                'transaction_id' => $transaction->id,
                'file_path' => $path,
                'file_name' => SafeUploadedFileName::forReceipt($receipt),
                'file_type' => $receipt->getClientMimeType(),
            ]);

            $applied = $this->applyFifo($contract->id, $toCollect, (int) $transaction->id);
            $summary = $this->residualBalanceService->summary($contract->id);

            return [
                'transaction_id' => (int) $transaction->id,
                'amount' => $toCollect,
                'pending_residual_balance' => $summary['pending_sum'],
                'residual_balance_collectible' => $summary['collectible'],
                'residual_collectible_threshold' => $summary['collectible_threshold'],
                'collected_row_ids' => $applied['collected_row_ids'],
                'partial_row_id' => $applied['partial_row_id'],
            ];
        });
    }

    /**
     * @return array{collected_row_ids: list<int>, partial_row_id: int|null}
     */
    private function applyFifo(int $contractId, string $toCollect, int $transactionId): array
    {
        $rows = ContractResidualBalance::query()
            ->where('contract_id', $contractId)
            ->where('status', ResidualBalanceStatus::PENDIENTE)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $toCollect;
        $collectedIds = [];
        $partialId = null;
        $now = Carbon::now();

        foreach ($rows as $row) {
            if (bccomp($remaining, '0.00', 2) <= 0) {
                break;
            }

            $rowAmount = $this->money((string) $row->amount);

            if (bccomp($remaining, $rowAmount, 2) >= 0) {
                $row->update([
                    'status' => ResidualBalanceStatus::COBRADO,
                    'collected_transaction_id' => $transactionId,
                    'collected_at' => $now,
                ]);
                $collectedIds[] = (int) $row->id;
                $remaining = $this->money(bcsub($remaining, $rowAmount, 2));

                continue;
            }

            $row->update([
                'amount' => $this->money(bcsub($rowAmount, $remaining, 2)),
                'last_partial_transaction_id' => $transactionId,
                'last_partial_amount' => $remaining,
            ]);
            $partialId = (int) $row->id;
            $remaining = '0.00';
        }

        return [
            'collected_row_ids' => $collectedIds,
            'partial_row_id' => $partialId,
        ];
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
