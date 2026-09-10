<?php

namespace App\Services\Collection;

use App\Enums\AmortizationStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use App\Services\Financial\Transaction\InstallmentPaymentAllocator;
use App\Services\Residual\ResidualBalanceService;
use App\Support\ReceiptNumber;
use App\Support\SafeUploadedFileName;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CascadeCollectionService
{
    public const SURPLUS_ACTION_REQUIRED = 'Este pago supera lo que se debe. Indica qué hacer con el excedente.';

    public function __construct(
        private readonly ExtraordinaryPaymentService $extraordinaryPaymentService,
        private readonly InstallmentPaymentAllocator $allocator,
        private readonly TransactionAllocationRecorder $allocationRecorder,
        private readonly PaymentPromiseAllocationService $promiseAllocationService,
        private readonly ResidualBalanceService $residualBalanceService,
    ) {}

    public function process(
        int $contractId,
        string $amount,
        ?string $paymentOption = null,
        ?Carbon $transactionDate = null,
        array $selectedInstallmentIds = [],
        ?UploadedFile $receipt = null,
        ?PaymentMethod $paymentMethod = null,
        ?string $notes = null,
        bool $persistTransaction = true,
        ?int $allocationTransactionId = null,
        ?string $receiptNumber = null,
    ): array {
        return DB::transaction(function () use ($contractId, $amount, $paymentOption, $transactionDate, $selectedInstallmentIds, $receipt, $paymentMethod, $notes, $persistTransaction, $allocationTransactionId, $receiptNumber) {
            $contract = Contract::findOrFail($contractId);
            $availableAmount = $this->normalizeMoney($amount);
            $processedAmount = '0.00';
            $appliedInstallments = [];
            $normalizedPaymentOption = $this->normalizePaymentOption($paymentOption);
            $effectiveTransactionDate = ($transactionDate ?? Carbon::now())->copy()->startOfDay();
            $selectedIds = array_values(array_unique(array_filter(
                array_map('intval', $selectedInstallmentIds),
                fn (int $id) => $id > 0,
            )));
            $hasExplicitSelection = $selectedIds !== [];
            $hasExtraordinaryOption = $this->isExtraordinaryOption($normalizedPaymentOption);

            $transaction = null;
            if ($persistTransaction) {
                $normalizedReceipt = ReceiptNumber::normalize($receiptNumber);
                $transaction = Transaction::create([
                    'contract_id' => $contract->id,
                    'transaction_type' => TransactionType::REGULAR_PAYMENT,
                    'amount' => $availableAmount,
                    'transaction_date' => $effectiveTransactionDate->toDateString(),
                    'payment_method' => $paymentMethod ?? PaymentMethod::CASH,
                    'notes' => ReceiptNumber::mergeIntoNotes($notes, $normalizedReceipt),
                    'receipt_number' => $normalizedReceipt,
                    'payment_option' => $normalizedPaymentOption,
                ]);

                if ($receipt) {
                    $path = $receipt->store('receipts', 'local');

                    Receipt::create([
                        'transaction_id' => $transaction->id,
                        'file_path' => $path,
                        'file_name' => SafeUploadedFileName::forReceipt($receipt),
                        'file_type' => $receipt->getClientMimeType(),
                    ]);
                }
            }

            $willRecordAllocations = $persistTransaction || $allocationTransactionId !== null;
            $allocationSnapshots = $willRecordAllocations
                ? $this->allocationRecorder->snapshotRegulars($contract)
                : [];

            $pendingInstallments = $this->getPendingInstallments($contract, $selectedIds)->values();
            $injectedCurrent = $this->allocator->unpaidCurrentInstallments($contract)
                ->filter(fn (AmortizationInstallment $row) => ! in_array((int) $row->id, $selectedIds, true))
                ->values();
            $totalInstallments = $pendingInstallments->count();
            $processedIds = [];

            foreach ($pendingInstallments as $index => $installment) {
                if (bccomp($availableAmount, '0.00', 2) <= 0) {
                    break;
                }

                if ($installment->status === AmortizationStatus::PAID) {
                    continue;
                }

                // Plazo, cuota y abono a capital absorben el sobrante en la última
                // seleccionada. Adelantar cuotas deja el resto para la cascada FIFO.
                $isLastSelected = $hasExplicitSelection
                    && in_array($normalizedPaymentOption, ['reducir_plazo', 'reducir_cuota', 'abono_capital'], true)
                    && ($index === $totalInstallments - 1);
                $balanceDue = $this->normalizeMoney((string) ($installment->quota_debt ?? $installment->remaining_balance ?? $installment->installment_value ?? '0.00'));
                $amountToDebt = bccomp($availableAmount, $balanceDue, 2) <= 0
                    ? $availableAmount
                    : $balanceDue;

                if (bccomp($amountToDebt, '0.00', 2) <= 0) {
                    continue;
                }

                $allocation = $this->allocator->applyToInstallment(
                    $installment,
                    $amountToDebt,
                    $effectiveTransactionDate,
                );

                $surplusAmount = '0.00';
                $amountToApply = $allocation['applied'];

                if ($isLastSelected) {
                    $surplusAmount = $this->normalizeMoney(bcsub($availableAmount, $allocation['applied'], 2));
                    $amountToApply = $availableAmount;
                }

                $availableAmount = $this->normalizeMoney(bcsub($availableAmount, $amountToApply, 2));
                $processedAmount = $this->normalizeMoney(bcadd($processedAmount, $amountToApply, 2));
                $processedIds[] = (int) $installment->id;

                $appliedInstallments[] = [
                    'installment_id' => $installment->id,
                    'installment_number' => $installment->installment_number,
                    'amount_applied' => $amountToApply,
                    'balance_due' => $allocation['quota_debt'],
                    'status' => $allocation['status'],
                ];

                if (
                    $isLastSelected
                    && bccomp($surplusAmount, '0.00', 2) > 0
                    && in_array($normalizedPaymentOption, ['reducir_plazo', 'reducir_cuota', 'abono_capital'], true)
                ) {
                    $extraordinaryInstallment = $installment->fresh();

                    if ($extraordinaryInstallment) {
                        $this->extraordinaryPaymentService->handle(
                            $contract,
                            $extraordinaryInstallment,
                            $surplusAmount,
                            $normalizedPaymentOption,
                        );

                        $extraordinaryInstallment->refresh();
                        $extraordinaryInstallment->update([
                            'payment_date' => $effectiveTransactionDate->toDateString(),
                            'status' => AmortizationStatus::PAID->value,
                        ]);
                    }
                }
            }

            // Hueco A cobra la corriente en la cola. El sobrante de ESA
            // corriente (no el de mora) vuelve a handle() como cuando la
            // corriente estaba "libre" en la rama sin selección.
            $injectedCurrentPaid = $this->lastProcessedInjectedCurrent($injectedCurrent, $processedIds);
            if (
                $hasExtraordinaryOption
                && ! $hasExplicitSelection
                && $injectedCurrentPaid
                && bccomp($availableAmount, '0.00', 2) > 0
            ) {
                $this->absorbSurplusViaHandle(
                    $contract,
                    $injectedCurrentPaid,
                    $availableAmount,
                    (string) $normalizedPaymentOption,
                    $effectiveTransactionDate,
                    $appliedInstallments,
                    $processedAmount,
                );
                $availableAmount = '0.00';
            }

            if ($hasExtraordinaryOption && ! $hasExplicitSelection && bccomp($availableAmount, '0.00', 2) > 0) {
                $implicitTarget = $this->nextNonOverduePendingInstallment(
                    $contract,
                    $processedIds,
                    $effectiveTransactionDate,
                );

                if (! $implicitTarget) {
                    throw ValidationException::withMessages([
                        'amount' => 'La obligación ya fue cumplida, no hay saldo pendiente para aplicar este pago.',
                    ]);
                }

                $targetBalanceDue = $this->normalizeMoney((string) ($implicitTarget->quota_debt ?? $implicitTarget->remaining_balance ?? $implicitTarget->installment_value ?? '0.00'));
                $targetAmountToDebt = bccomp($availableAmount, $targetBalanceDue, 2) <= 0
                    ? $availableAmount
                    : $targetBalanceDue;

                if (bccomp($targetAmountToDebt, '0.00', 2) > 0) {
                    $targetAllocation = $this->allocator->applyToInstallment(
                        $implicitTarget,
                        $targetAmountToDebt,
                        $effectiveTransactionDate,
                    );

                    $targetSurplus = $this->normalizeMoney(bcsub($availableAmount, $targetAllocation['applied'], 2));
                    $targetAmountApplied = $availableAmount;

                    $availableAmount = $this->normalizeMoney(bcsub($availableAmount, $targetAmountApplied, 2));
                    $processedAmount = $this->normalizeMoney(bcadd($processedAmount, $targetAmountApplied, 2));
                    $processedIds[] = (int) $implicitTarget->id;

                    if ($this->allocator->leftoverExceedsTolerance($targetSurplus)) {
                        $this->extraordinaryPaymentService->handle(
                            $contract,
                            $implicitTarget->fresh(),
                            $targetSurplus,
                            (string) $normalizedPaymentOption,
                        );
                    }

                    $implicitTarget->refresh();

                    $appliedInstallments[] = [
                        'installment_id' => $implicitTarget->id,
                        'installment_number' => $implicitTarget->installment_number,
                        'amount_applied' => $targetAmountApplied,
                        'balance_due' => $this->normalizeMoney((string) ($implicitTarget->quota_debt ?? '0.00')),
                        'status' => $implicitTarget->status instanceof AmortizationStatus
                            ? $implicitTarget->status->value
                            : (string) $implicitTarget->status,
                    ];
                }
            }

            if ($this->allocator->leftoverExceedsTolerance($availableAmount)) {
                if ($normalizedPaymentOption === 'adelantar_cuotas') {
                    $cascade = $this->allocator->cascadeToPending(
                        $contract,
                        $availableAmount,
                        $effectiveTransactionDate,
                        $processedIds,
                    );

                    foreach ($cascade['installments'] as $applied) {
                        $processedAmount = $this->normalizeMoney(bcadd($processedAmount, $applied['amount_applied'], 2));
                        $appliedInstallments[] = $applied;
                        $processedIds[] = (int) $applied['installment_id'];
                    }

                    $availableAmount = $cascade['remaining'];
                } elseif ($normalizedPaymentOption === null || $normalizedPaymentOption === '') {
                    if (bccomp($processedAmount, '0.00', 2) > 0) {
                        throw ValidationException::withMessages([
                            'payment_option' => self::SURPLUS_ACTION_REQUIRED,
                        ]);
                    }

                    // Pago general sin mora ni selección: cubre la siguiente
                    // cuota. Si alcanza para más, ya es excedente.
                    $cascade = $this->allocator->cascadeToPending(
                        $contract,
                        $availableAmount,
                        $effectiveTransactionDate,
                        $processedIds,
                    );

                    if ($cascade['installments'] !== []
                        && (
                            count($cascade['installments']) > 1
                            || $this->allocator->leftoverExceedsTolerance($cascade['remaining'])
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'payment_option' => self::SURPLUS_ACTION_REQUIRED,
                        ]);
                    }

                    foreach ($cascade['installments'] as $applied) {
                        $processedAmount = $this->normalizeMoney(bcadd($processedAmount, $applied['amount_applied'], 2));
                        $appliedInstallments[] = $applied;
                        $processedIds[] = (int) $applied['installment_id'];
                    }

                    $availableAmount = $cascade['remaining'];
                }
            }

            if (
                bccomp($processedAmount, '0.00', 2) <= 0
                || $this->allocator->leftoverExceedsTolerance($availableAmount)
            ) {
                throw ValidationException::withMessages([
                    'amount' => 'La obligación ya fue cumplida, no hay saldo pendiente para aplicar este pago.',
                ]);
            }

            $recordedTransactionId = $transaction?->id ?? $allocationTransactionId;
            if ($recordedTransactionId && $appliedInstallments !== []) {
                $this->allocationRecorder->recordAppliedInstallments(
                    $recordedTransactionId,
                    $appliedInstallments,
                    $allocationSnapshots,
                );

                $sourceTransaction = $transaction ?? Transaction::query()->find($recordedTransactionId);
                if ($sourceTransaction) {
                    $this->promiseAllocationService->allocate(
                        $contract,
                        $sourceTransaction,
                        $processedAmount,
                    );
                }
            }

            $residualSummary = $this->residualBalanceService->summary((int) $contract->id);

            return [
                'transaction_id' => $transaction?->id,
                'contract_id' => $contract->id,
                'amount' => $this->normalizeMoney($amount),
                'amount_applied' => $processedAmount,
                'remaining_amount' => '0.00',
                'installments' => $appliedInstallments,
                'application_notice' => $this->buildApplicationNotice(
                    $injectedCurrent,
                    $appliedInstallments,
                    $selectedIds,
                    $contract,
                ),
                'pending_residual_balance' => $residualSummary['pending_sum'],
                'residual_balance_collectible' => $residualSummary['collectible'],
            ];
        });
    }

    private function isExtraordinaryOption(?string $paymentOption): bool
    {
        return in_array($paymentOption, ['reducir_plazo', 'reducir_cuota', 'adelantar_cuotas', 'abono_capital'], true);
    }

    private function normalizePaymentOption(?string $paymentOption): ?string
    {
        if ($paymentOption === null || trim((string) $paymentOption) === '') {
            return null;
        }

        $normalizedOption = strtolower(trim((string) $paymentOption));

        return match ($normalizedOption) {
            'reduce_time', 'reducir_plazo' => 'reducir_plazo',
            'reduce_quota', 'reducir_cuota' => 'reducir_cuota',
            'transfer', 'adelantar_cuotas' => 'adelantar_cuotas',
            'abono_capital' => 'abono_capital',
            default => $normalizedOption,
        };
    }

    /**
     * Aviso solo si la corriente se inyectó (no estaba seleccionada) y recibió dinero.
     */
    private function buildApplicationNotice(
        EloquentCollection $injectedCurrent,
        array $appliedInstallments,
        array $selectedIds,
        Contract $contract,
    ): ?string {
        foreach ($injectedCurrent as $current) {
            $applied = null;
            foreach ($appliedInstallments as $row) {
                if ((int) ($row['installment_id'] ?? 0) === (int) $current->id
                    && bccomp((string) ($row['amount_applied'] ?? '0.00'), '0.00', 2) > 0
                ) {
                    $applied = $row;
                    break;
                }
            }

            if ($applied === null) {
                continue;
            }

            $amountLabel = $this->formatNoticeAmount((string) $applied['amount_applied']);
            $currentNumber = (int) $current->installment_number;
            $selectedOtherId = null;

            foreach ($selectedIds as $id) {
                if ($id !== (int) $current->id) {
                    $selectedOtherId = $id;
                    break;
                }
            }

            if ($selectedOtherId === null) {
                return "Se aplicó {$amountLabel} a la cuota corriente #{$currentNumber} porque estaba pendiente de este mes.";
            }

            $selectedNumber = (int) $contract->amortizationInstallments()
                ->where('id', $selectedOtherId)
                ->value('installment_number');

            return "Se aplicó {$amountLabel} a la cuota corriente #{$currentNumber} antes que a la cuota #{$selectedNumber} que seleccionaste, porque estaba pendiente de este mes.";
        }

        return null;
    }

    private function formatNoticeAmount(string $amount): string
    {
        return '$'.number_format((float) $amount, 0, ',', '.');
    }

    private function getPendingInstallments(Contract $contract, array $selectedInstallmentIds = []): EloquentCollection
    {
        return $this->allocator->resolveInstallmentsToProcess($contract, $selectedInstallmentIds);
    }

    /**
     * Última corriente inyectada (no seleccionada) que ya recibió dinero
     * en la cola mora→corriente. Null si el sobrante viene solo de mora
     * y no hubo corriente en cola.
     */
    private function lastProcessedInjectedCurrent(
        EloquentCollection $injectedCurrent,
        array $processedIds,
    ): ?AmortizationInstallment {
        $processed = array_flip(array_map('intval', $processedIds));
        $last = null;

        foreach ($injectedCurrent as $row) {
            if (isset($processed[(int) $row->id])) {
                $last = $row;
            }
        }

        return $last;
    }

    /**
     * @param  list<array<string, mixed>>  $appliedInstallments
     */
    private function absorbSurplusViaHandle(
        Contract $contract,
        AmortizationInstallment $installment,
        string $surplusAmount,
        string $option,
        Carbon $transactionDate,
        array &$appliedInstallments,
        string &$processedAmount,
    ): void {
        if (! $this->allocator->leftoverExceedsTolerance($surplusAmount)) {
            return;
        }

        $this->extraordinaryPaymentService->handle(
            $contract,
            $installment->fresh(),
            $surplusAmount,
            $option,
        );

        $fresh = $installment->fresh();
        if ($fresh) {
            $fresh->update([
                'payment_date' => $transactionDate->toDateString(),
                'status' => AmortizationStatus::PAID->value,
            ]);
        }

        $processedAmount = $this->normalizeMoney(bcadd($processedAmount, $surplusAmount, 2));

        foreach ($appliedInstallments as $index => $applied) {
            if ((int) ($applied['installment_id'] ?? 0) !== (int) $installment->id) {
                continue;
            }

            $appliedInstallments[$index]['amount_applied'] = $this->normalizeMoney(
                bcadd((string) ($applied['amount_applied'] ?? '0.00'), $surplusAmount, 2)
            );
            $appliedInstallments[$index]['status'] = AmortizationStatus::PAID->value;

            break;
        }
    }

    private function nextNonOverduePendingInstallment(
        Contract $contract,
        array $excludeIds,
        Carbon $transactionDate,
    ): ?AmortizationInstallment {
        $query = $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->where(function ($query) {
                $query->where('quota_debt', '>', 0)
                    ->orWhere('remaining_balance', '>', 0);
            })
            ->whereDate('due_date', '>=', $transactionDate->toDateString())
            ->orderBy('due_date', 'asc')
            ->orderBy('installment_number', 'asc');

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query->first();
    }

    private function normalizeMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
