<?php

namespace App\Services\Collection;

use App\Enums\AmortizationStatusEnum;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\AmortizationPlan;
use App\Models\Contract;
use App\Models\Transaction;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class CascadeCollectionService
{
    public function __construct(
        private readonly ExtraordinaryPaymentService $extraordinaryPaymentService,
    ) {}

    public function process(int $contractId, string $amount): array
    {
        return DB::transaction(function () use ($contractId, $amount) {
            $contract = Contract::findOrFail($contractId);
            $availableAmount = $this->normalizeMoney($amount);
            $processedAmount = '0.00';
            $appliedInstallments = [];
            $lastPaidInstallment = null;

            $transaction = Transaction::create([
                'contract_id' => $contract->id,
                'transaction_type' => TransactionType::REGULAR_PAYMENT,
                'amount' => $availableAmount,
                'transaction_date' => now()->toDateString(),
                'payment_method' => 'cash',
            ]);

            $pendingInstallments = $this->getPendingInstallments($contract);
            $firstPendingInstallment = $pendingInstallments->first(function ($installment) {
                $status = strtolower((string) ($installment->status ?? ''));

                return ! in_array($status, ['pagada', 'paid'], true);
            });

            if ($firstPendingInstallment) {
                $installment = $firstPendingInstallment;
                $balanceDue = $this->normalizeMoney((string) ($installment->quota_debt ?? $installment->remaining_balance ?? $installment->installment_value ?? '0.00'));
                $currentAmount = bccomp($availableAmount, $balanceDue, 2) <= 0
                    ? $availableAmount
                    : $balanceDue;

                $interestValue = $this->normalizeMoney((string) ($installment->interest_value ?? '0.00'));
                $interestApplied = bccomp($currentAmount, $interestValue, 2) <= 0
                    ? $currentAmount
                    : $interestValue;
                $amortizationApplied = max('0.00', $this->normalizeMoney(bcsub($currentAmount, $interestApplied, 2)));

                $newBalanceDue = max('0.00', $this->normalizeMoney(bcsub($balanceDue, $currentAmount, 2)));
                $status = bccomp($newBalanceDue, '0.00', 2) === 0
                    ? AmortizationStatusEnum::PAID->value
                    : AmortizationStatusEnum::PARTIAL->value;

                $installment->update([
                    'quota_debt' => $newBalanceDue,
                    'status' => $status,
                    'payment_date' => now(),
                ]);

                $lastPaidInstallment = $installment;
                $availableAmount = $this->normalizeMoney(bcsub($availableAmount, $currentAmount, 2));
                $processedAmount = $this->normalizeMoney(bcadd($processedAmount, $currentAmount, 2));

                $appliedInstallments[] = [
                    'installment_id' => $installment->id,
                    'installment_number' => $installment->installment_number,
                    'amount_applied' => $currentAmount,
                    'balance_due' => $newBalanceDue,
                    'status' => $status,
                ];
            }

            if (bccomp($availableAmount, '0.00', 2) > 0 && $lastPaidInstallment) {
                $surplusAmount = $availableAmount;
                $extraordinaryInstallment = $this->resolveExtraordinaryInstallment($contract, $lastPaidInstallment);

                if ($extraordinaryInstallment) {
                    $this->extraordinaryPaymentService->handle(
                        $contract,
                        $extraordinaryInstallment,
                        $surplusAmount,
                        'reducir_plazo',
                    );

                    if ($extraordinaryInstallment instanceof AmortizationInstallment) {
                        $extraordinaryInstallment->refresh();
                        $extraordinaryInstallment->update([
                            'payment_date' => now(),
                            'status' => AmortizationStatusEnum::PAID->value,
                        ]);
                    }

                    $processedAmount = $this->normalizeMoney(bcadd($processedAmount, $surplusAmount, 2));
                    $availableAmount = '0.00';
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

    private function getPendingInstallments(Contract $contract): EloquentCollection
    {
        if ($contract->amortizationInstallments()->exists()) {
            return $contract->amortizationInstallments()
                ->where(function ($query) {
                    $query->where('quota_debt', '>', 0)
                        ->orWhere('remaining_balance', '>', 0);
                })
                ->orderBy('due_date', 'asc')
                ->orderBy('installment_number', 'asc')
                ->get();
        }

        return AmortizationPlan::query()
            ->where('contract_id', $contract->id)
            ->where(function ($query) {
                $query->where('balance_due', '>', 0)
                    ->orWhere('quota_debt', '>', 0);
            })
            ->orderBy('due_date', 'asc')
            ->orderBy('installment_number', 'asc')
            ->get();
    }

    private function resolveExtraordinaryInstallment(Contract $contract, $installment): AmortizationInstallment|AmortizationPlan|null
    {
        if ($contract->amortizationInstallments()->exists()) {
            if ($installment instanceof AmortizationInstallment) {
                return $installment->fresh();
            }

            return $contract->amortizationInstallments()
                ->where('installment_number', $installment->installment_number ?? 0)
                ->first();
        }

        if ($installment instanceof AmortizationPlan) {
            return $installment->fresh();
        }

        return AmortizationPlan::query()
            ->where('contract_id', $contract->id)
            ->where('installment_number', $installment->installment_number ?? 0)
            ->first();
    }

    private function applyLegacyPlanSurplus(AmortizationPlan $installment, string $surplusAmount): void
    {
        $installment->update([
            'balance_due' => '0.00',
            'status' => AmortizationStatusEnum::PAID->value,
            'payment_date' => now(),
        ]);
    }

    private function normalizeMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
