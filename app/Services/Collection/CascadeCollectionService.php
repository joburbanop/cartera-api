<?php

namespace App\Services\Collection;

use App\Enums\AmortizationStatusEnum;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;      
use Illuminate\Support\Facades\DB;

class CascadeCollectionService
{
    public function __construct(
        private readonly ExtraordinaryPaymentService $extraordinaryPaymentService,
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
            $lastPaidInstallment = null;
            $normalizedPaymentOption = $this->normalizePaymentOption($paymentOption);
            $effectiveTransactionDate = ($transactionDate ?? Carbon::now())->copy()->startOfDay();
            $hasExplicitSelection = ! empty($selectedInstallmentIds);

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

            foreach ($pendingInstallments as $index => $installment) {
                if (bccomp($availableAmount, '0.00', 2) <= 0) {
                    break;
                }

                $status = strtolower((string) ($installment->status ?? ''));
                if (in_array($status, ['pagada', 'paid'], true)) {
                    continue;
                }

                $isLastSelected = $hasExplicitSelection && ($index === $totalInstallments - 1);
                $balanceDue = $this->normalizeMoney((string) ($installment->quota_debt ?? $installment->remaining_balance ?? $installment->installment_value ?? '0.00'));
                $amountToApply = $isLastSelected
                    ? $availableAmount
                    : (bccomp($availableAmount, $balanceDue, 2) <= 0 ? $availableAmount : $balanceDue);

                if (bccomp($amountToApply, '0.00', 2) <= 0) {
                    continue;
                }

                $amountToDebt = bccomp($amountToApply, $balanceDue, 2) <= 0
                    ? $amountToApply
                    : $balanceDue;

                $surplusAmount = bccomp($amountToApply, $amountToDebt, 2) === 1
                    ? $this->normalizeMoney(bcsub($amountToApply, $amountToDebt, 2))
                    : '0.00';

                $interestValue = $this->normalizeMoney((string) ($installment->interest_value ?? '0.00'));
                $interestApplied = bccomp($amountToDebt, $interestValue, 2) <= 0
                    ? $amountToDebt
                    : $interestValue;
                $amortizationApplied = max('0.00', $this->normalizeMoney(bcsub($amountToDebt, $interestApplied, 2)));

                $newBalanceDue = max('0.00', $this->normalizeMoney(bcsub($balanceDue, $amountToDebt, 2)));

                // Aplicar tolerancia de redondeo: saldos menores a $1.00 se consideran pagados.
                if ((float) $newBalanceDue > 0 && (float) $newBalanceDue < 1.00) {
                    $newBalanceDue = '0.00';
                }

                $status = (float) $newBalanceDue <= 0
                    ? AmortizationStatusEnum::PAID->value
                    : AmortizationStatusEnum::PARTIAL->value;

                $installment->update([
                    'quota_debt' => $newBalanceDue,
                    'status' => $status,
                    'payment_date' => $effectiveTransactionDate->toDateString(),
                ]);

                $lastPaidInstallment = $installment;
                $availableAmount = $this->normalizeMoney(bcsub($availableAmount, $amountToApply, 2));
                $processedAmount = $this->normalizeMoney(bcadd($processedAmount, $amountToApply, 2));

                $appliedInstallments[] = [
                    'installment_id' => $installment->id,
                    'installment_number' => $installment->installment_number,
                    'amount_applied' => $amountToApply,
                    'balance_due' => $newBalanceDue,
                    'status' => $status,
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
                            'status' => AmortizationStatusEnum::PAID->value,
                        ]);
                    }
                }
            }

            return [
                'transaction_id' => $transaction->id,
                'contract_id' => $contract->id,
                'amount' => $this->normalizeMoney($amount),
                'amount_applied' => $processedAmount,
                'remaining_amount' => $availableAmount,
                'installments' => $appliedInstallments,
            ];
        });
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

        $query = $contract->amortizationInstallments()
            ->where(function ($query) {
                $query->where('quota_debt', '>', 0)
                    ->orWhere('remaining_balance', '>', 0);
            })
            ->orderBy('due_date', 'asc')
            ->orderBy('installment_number', 'asc');

        if (! empty($selectedIds)) {
            $query->whereIn('id', $selectedIds);
        }

        return $query->get();
    }

    private function normalizeMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
