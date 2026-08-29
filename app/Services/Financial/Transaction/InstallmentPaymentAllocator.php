<?php

namespace App\Services\Financial\Transaction;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

class InstallmentPaymentAllocator
{
    public function applyToInstallment(
        AmortizationInstallment $installment,
        string $paymentAmount,
        Carbon $paymentDate,
    ): array {
        $paymentAmount = $this->normalizeMoney($paymentAmount);

        if (bccomp($paymentAmount, '0.00', 2) <= 0) {
            return [
                'applied' => '0.00',
                'quota_debt' => $this->normalizeMoney((string) ($installment->quota_debt ?? '0.00')),
                'status' => $this->statusValue($installment),
                'interest_paid' => $this->normalizeMoney((string) ($installment->interest_paid ?? '0.00')),
                'principal_paid' => $this->normalizeMoney((string) ($installment->principal_paid ?? '0.00')),
            ];
        }

        $installmentValue = $this->normalizeMoney((string) ($installment->installment_value ?? '0.00'));
        $interestValue = $this->normalizeMoney((string) ($installment->interest_value ?? '0.00'));
        $principalValue = $this->normalizeMoney((string) (
            $installment->principal_value
            ?? bcsub($installmentValue, $interestValue, 2)
        ));
        $interestAlreadyPaid = $this->normalizeMoney((string) ($installment->interest_paid ?? '0.00'));
        $principalAlreadyPaid = $this->normalizeMoney((string) ($installment->principal_paid ?? '0.00'));
        $currentQuotaDebt = $this->normalizeMoney((string) ($installment->quota_debt ?? '0.00'));

        $pendingDebt = bccomp($currentQuotaDebt, '0.00', 2) > 0
            ? $currentQuotaDebt
            : $this->maxZero(bcsub($installmentValue, bcadd($interestAlreadyPaid, $principalAlreadyPaid, 2), 2));

        $amountToDebt = bccomp($paymentAmount, $pendingDebt, 2) <= 0
            ? $paymentAmount
            : $pendingDebt;

        $remainingInterest = $this->maxZero(bcsub($interestValue, $interestAlreadyPaid, 2));
        $interestApplied = bccomp($amountToDebt, $remainingInterest, 2) <= 0
            ? $amountToDebt
            : $remainingInterest;
        $leftForPrincipal = $this->maxZero(bcsub($amountToDebt, $interestApplied, 2));
        $remainingPrincipal = $this->maxZero(bcsub($principalValue, $principalAlreadyPaid, 2));
        $principalApplied = bccomp($leftForPrincipal, $remainingPrincipal, 2) <= 0
            ? $leftForPrincipal
            : $remainingPrincipal;

        $newInterestPaid = bcadd($interestAlreadyPaid, $interestApplied, 2);
        $newPrincipalPaid = bcadd($principalAlreadyPaid, $principalApplied, 2);
        $newBalanceDue = $this->maxZero(bcsub($pendingDebt, $amountToDebt, 2));

        if (bccomp($newBalanceDue, '0.00', 2) > 0 && bccomp($newBalanceDue, '1.00', 2) < 0) {
            $newBalanceDue = '0.00';
        }

        $isPaid = bccomp($newBalanceDue, '0.00', 2) <= 0;

        if ($isPaid) {
            $newInterestPaid = $interestValue;
            $newPrincipalPaid = $principalValue;
        }

        $status = $isPaid
            ? AmortizationStatus::PAID->value
            : AmortizationStatus::PARTIAL->value;

        $installment->update([
            'quota_debt' => $newBalanceDue,
            'status' => $status,
            'payment_date' => $paymentDate->toDateString(),
            'interest_paid' => $newInterestPaid,
            'principal_paid' => $newPrincipalPaid,
        ]);

        return [
            'applied' => $amountToDebt,
            'quota_debt' => $newBalanceDue,
            'status' => $status,
            'interest_paid' => $newInterestPaid,
            'principal_paid' => $newPrincipalPaid,
        ];
    }

    public function cascadeToPending(
        Contract $contract,
        string $amount,
        Carbon $paymentDate,
        array $excludeIds = [],
    ): array {
        return $this->cascadeToInstallments(
            $this->pendingInstallments($contract, $excludeIds),
            $amount,
            $paymentDate,
        );
    }

    public function cascadeToInstallments(iterable $installments, string $amount, Carbon $paymentDate): array
    {
        $available = $this->normalizeMoney($amount);
        $appliedInstallments = [];

        foreach ($installments as $installment) {
            if (bccomp($available, '0.00', 2) <= 0) {
                break;
            }

            if ($installment->status === AmortizationStatus::PAID) {
                continue;
            }

            $result = $this->applyToInstallment($installment, $available, $paymentDate);

            if (bccomp($result['applied'], '0.00', 2) <= 0) {
                continue;
            }

            $available = $this->normalizeMoney(bcsub($available, $result['applied'], 2));
            $appliedInstallments[] = [
                'installment_id' => $installment->id,
                'installment_number' => $installment->installment_number,
                'amount_applied' => $result['applied'],
                'balance_due' => $result['quota_debt'],
                'status' => $result['status'],
            ];
        }

        return [
            'remaining' => $available,
            'installments' => $appliedInstallments,
        ];
    }

    public function priorUnpaidInstallments(Contract $contract, AmortizationInstallment $target): EloquentCollection
    {
        $targetNumber = (int) ($target->installment_number ?? 0);

        return $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->where('installment_number', '<', $targetNumber)
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->where(function ($query) {
                $query->where('quota_debt', '>', 0)
                    ->orWhere('status', AmortizationStatus::OVERDUE->value);
            })
            ->orderBy('due_date', 'asc')
            ->orderBy('installment_number', 'asc')
            ->get();
    }

    public function settlePriorUnpaidOrFail(
        Contract $contract,
        AmortizationInstallment $target,
        string $amount,
        Carbon $paymentDate,
    ): string {
        $amount = $this->normalizeMoney($amount);
        $prior = $this->priorUnpaidInstallments($contract, $target);
        $priorDebt = $this->sumQuotaDebt($prior);

        if (bccomp($priorDebt, '0.00', 2) <= 0) {
            return $amount;
        }

        if (bccomp($amount, $priorDebt, 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => 'Debe saldar primero las cuotas atrasadas antes de aplicar un abono extraordinario.',
            ]);
        }

        $result = $this->cascadeToInstallments($prior, $amount, $paymentDate);

        return $result['remaining'];
    }

    public function pendingInstallments(
        Contract $contract,
        array $excludeIds = [],
        array $onlyIds = [],
    ): EloquentCollection {
        $query = $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->where(function ($query) {
                $query->where('quota_debt', '>', 0)
                    ->orWhere('remaining_balance', '>', 0);
            })
            ->orderBy('due_date', 'asc')
            ->orderBy('installment_number', 'asc');

        if ($onlyIds !== []) {
            $query->whereIn('id', array_values(array_filter(array_map('intval', $onlyIds), fn (int $id) => $id > 0)));
        }

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query->get();
    }

    public function leftoverExceedsTolerance(string $amount): bool
    {
        return bccomp($this->normalizeMoney($amount), '2.00', 2) > 0;
    }

    private function sumQuotaDebt(iterable $installments): string
    {
        $total = '0.00';

        foreach ($installments as $installment) {
            $debt = $this->normalizeMoney((string) ($installment->quota_debt ?? '0.00'));
            if (bccomp($debt, '0.00', 2) > 0) {
                $total = $this->normalizeMoney(bcadd($total, $debt, 2));
            }
        }

        return $total;
    }

    private function statusValue(AmortizationInstallment $installment): string
    {
        $status = $installment->status;

        return $status instanceof AmortizationStatus ? $status->value : (string) $status;
    }

    private function maxZero(string $value): string
    {
        return bccomp($value, '0.00', 2) < 0 ? '0.00' : $this->normalizeMoney($value);
    }

    private function normalizeMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
