<?php

namespace App\Services\Collection;

use App\Models\Receipt;
use Illuminate\Http\UploadedFile;
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

    public function process(int $contractId, string $amount, ?string $paymentOption = null, ?UploadedFile $receipt = null,
        ): array
    {
        return DB::transaction(function () use ($contractId, $amount, $paymentOption, $receipt) {
            $contract = Contract::findOrFail($contractId);
            $availableAmount = $this->normalizeMoney($amount);
            $processedAmount = '0.00';
            $appliedInstallments = [];
            $lastPaidInstallment = null;
            $normalizedPaymentOption = $this->normalizePaymentOption($paymentOption);

            $transaction = Transaction::create([
                'contract_id' => $contract->id,
                'transaction_type' => TransactionType::REGULAR_PAYMENT,
                'amount' => $availableAmount,
                'transaction_date' => now()->toDateString(),
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
            $pendingInstallments = $this->getPendingInstallments($contract);
            $index = 0;

            while (bccomp($availableAmount, '0.00', 2) > 0 && $index < $pendingInstallments->count()) {
                $installment = $pendingInstallments->get($index);
                if ($installment === null) {
                    break;
                }

                $status = strtolower((string) ($installment->status ?? ''));
                if (in_array($status, ['pagada', 'paid'], true)) {
                    $index++;

                    continue;
                }

                $balanceDue = $this->normalizeMoney((string) ($installment->quota_debt ?? $installment->remaining_balance ?? $installment->installment_value ?? '0.00'));
                $amountToApply = bccomp($availableAmount, $balanceDue, 2) <= 0
                    ? $availableAmount
                    : $balanceDue;

                $interestValue = $this->normalizeMoney((string) ($installment->interest_value ?? '0.00'));
                $interestApplied = bccomp($amountToApply, $interestValue, 2) <= 0
                    ? $amountToApply
                    : $interestValue;
                $amortizationApplied = max('0.00', $this->normalizeMoney(bcsub($amountToApply, $interestApplied, 2)));

                $newBalanceDue = max('0.00', $this->normalizeMoney(bcsub($balanceDue, $amountToApply, 2)));

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
                    'payment_date' => now(),
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

                if (bccomp($availableAmount, '0.00', 2) > 0) {
                    if (in_array($normalizedPaymentOption, ['reducir_plazo', 'reducir_cuota'], true)) {
                        $surplusAmount = $availableAmount;
                        $extraordinaryInstallment = $this->resolveExtraordinaryInstallment($contract, $installment);

                        if ($extraordinaryInstallment) {
                            $this->extraordinaryPaymentService->handle(
                                $contract,
                                $extraordinaryInstallment,
                                $surplusAmount,
                                $normalizedPaymentOption,
                            );

                            if ($extraordinaryInstallment instanceof AmortizationInstallment) {
                                $extraordinaryInstallment->refresh();
                                $extraordinaryInstallment->update([
                                    'payment_date' => now(),
                                    'status' => AmortizationStatusEnum::PAID->value,
                                ]);
                            }
                        }

                        $processedAmount = $this->normalizeMoney(bcadd($processedAmount, $surplusAmount, 2));
                        $availableAmount = '0.00';
                        break;
                    }

                    $index++;

                    continue;
                }

                $index++;
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
