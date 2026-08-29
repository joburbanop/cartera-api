<?php

namespace App\Services\Collection;

use App\Enums\AmortizationStatus;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use App\Services\Financial\Transaction\InstallmentPaymentAllocator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CascadeCollectionService
{
    public function __construct(
        private readonly ExtraordinaryPaymentService $extraordinaryPaymentService,
        private readonly InstallmentPaymentAllocator $allocator,
    ) {}

    public function process(
        int $contractId,
        string $amount,
        ?string $paymentOption = null,
        ?Carbon $transactionDate = null,
        array $selectedInstallmentIds = [],
        ?UploadedFile $receipt = null,
    ): array {
        return DB::transaction(function () use ($contractId, $amount, $paymentOption, $transactionDate, $selectedInstallmentIds, $receipt) {
            $contract = Contract::findOrFail($contractId);
            $availableAmount = $this->normalizeMoney($amount);
            $processedAmount = '0.00';
            $appliedInstallments = [];
            $normalizedPaymentOption = $this->normalizePaymentOption($paymentOption);
            $effectiveTransactionDate = ($transactionDate ?? Carbon::now())->copy()->startOfDay();
            $hasExplicitSelection = ! empty($selectedInstallmentIds);
            $hasExtraordinaryOption = $this->isExtraordinaryOption($normalizedPaymentOption);

            $transaction = Transaction::create([
                'contract_id' => $contract->id,
                'transaction_type' => TransactionType::REGULAR_PAYMENT,
                'amount' => $availableAmount,
                'transaction_date' => $effectiveTransactionDate->toDateString(),
                'payment_method' => 'cash',
            ]);

            if ($receipt) {
                $path = $receipt->store('receipts', 'local');

                Receipt::create([
                    'transaction_id' => $transaction->id,
                    'file_path' => $path,
                    'file_name' => $receipt->getClientOriginalName(),
                    'file_type' => $receipt->getClientMimeType(),
                ]);
            }

            $pendingInstallments = $this->getPendingInstallments($contract, $selectedInstallmentIds)->values();
            $totalInstallments = $pendingInstallments->count();
            $processedIds = [];

            foreach ($pendingInstallments as $index => $installment) {
                if (bccomp($availableAmount, '0.00', 2) <= 0) {
                    break;
                }

                if ($installment->status === AmortizationStatus::PAID) {
                    continue;
                }

                $isLastSelected = $hasExplicitSelection && $hasExtraordinaryOption && ($index === $totalInstallments - 1);
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
                    && in_array($normalizedPaymentOption, ['reducir_plazo', 'reducir_cuota'], true)
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

            if (! $hasExtraordinaryOption && $this->allocator->leftoverExceedsTolerance($availableAmount)) {
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
            }

            if (
                bccomp($processedAmount, '0.00', 2) <= 0
                || $this->allocator->leftoverExceedsTolerance($availableAmount)
            ) {
                throw ValidationException::withMessages([
                    'amount' => 'La obligación ya fue cumplida, no hay saldo pendiente para aplicar este pago.',
                ]);
            }

            return [
                'transaction_id' => $transaction->id,
                'contract_id' => $contract->id,
                'amount' => $this->normalizeMoney($amount),
                'amount_applied' => $processedAmount,
                'remaining_amount' => '0.00',
                'installments' => $appliedInstallments,
            ];
        });
    }

    private function isExtraordinaryOption(?string $paymentOption): bool
    {
        return in_array($paymentOption, ['reducir_plazo', 'reducir_cuota', 'adelantar_cuotas'], true);
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
            default => $normalizedOption,
        };
    }

    private function getPendingInstallments(Contract $contract, array $selectedInstallmentIds = []): EloquentCollection
    {
        $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedInstallmentIds), fn ($id) => $id > 0)));

        return $this->allocator->pendingInstallments(
            $contract,
            [],
            $selectedIds,
        );
    }

    private function normalizeMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
