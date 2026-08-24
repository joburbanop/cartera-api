<?php

namespace App\Services\Financial\Transaction;

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Models\AmortizationPlan;
use App\Models\Contract;
use App\Models\Transaction;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use App\Enums\TransactionType;

class TransactionService
{
    public function __construct(private DownPaymentService $downPaymentService) {}

    public function register(CreateTransactionDTO $dto): Transaction
{
    return match ($dto->transactionType) {
        TransactionType::DOWN_PAYMENT =>
            $this->downPaymentService->registerDownPayment($dto),

        default =>
            throw ValidationException::withMessages([
                'transaction_type' => 'Tipo de transacción no implementado.',
            ]),
    };
}

    public function calculatePaymentImpactForInstallment(AmortizationPlan $plan, string $paymentAmount, ?Contract $contract = null): array
    {
        $installmentValue = (string) $plan->installment_value;
        $interestValue = (string) ($plan->interest_value ?? '0.00');
        $principalValue = (string) ($plan->principal_value ?? bcsub($installmentValue, $interestValue, 2));
        $previousRemainingBalance = (string) ($plan->remaining_balance ?? $installmentValue);

        $rawInstallmentNumber = $plan->installment_number ?? null;
        $isInitialInstallment = $rawInstallmentNumber === 0
            || strtolower((string) $rawInstallmentNumber) === 'inicial'
            || strtolower((string) $rawInstallmentNumber) === 'incial';

        if ($isInitialInstallment) {
            $currentQuotaDebt = (string) ($plan->quota_debt ?? $installmentValue);
            $updatedQuotaDebt = max('0.00', bcsub($currentQuotaDebt, $paymentAmount, 2));
            $status = bccomp($updatedQuotaDebt, '0.00', 2) === 0
                ? AmortizationStatus::PAID
                : AmortizationStatus::PARTIAL;

            return [
                'status' => $status,
                'remaining_balance' => $previousRemainingBalance,
                'quota_debt' => $updatedQuotaDebt,
                'interest_paid' => '0.00',
                'principal_paid' => '0.00',
                'excedente' => bccomp($paymentAmount, $currentQuotaDebt, 2) > 0 ? bcsub($paymentAmount, $currentQuotaDebt, 2) : '0.00',
            ];
        }

        $interestAlreadyPaid = (string) ($plan->interest_paid ?? '0.00');
        $principalAlreadyPaid = (string) ($plan->principal_paid ?? '0.00');
        $totalAlreadyPaid = bcadd($interestAlreadyPaid, $principalAlreadyPaid, 2);
        $currentQuotaDebt = (string) ($plan->quota_debt ?? '0.00');
        $pendingDebt = bccomp($currentQuotaDebt, '0.00', 2) > 0
            ? $currentQuotaDebt
            : bcsub($installmentValue, $totalAlreadyPaid, 2);
        $pendingDebt = max('0.00', $pendingDebt);

        $remainingInterestToPay = bcsub($interestValue, $interestAlreadyPaid, 2);
        $remainingPrincipalToPay = bcsub($principalValue, $principalAlreadyPaid, 2);

        if (bccomp($paymentAmount, $pendingDebt, 2) < 0) {
            $interestPaidNow = bccomp($paymentAmount, $remainingInterestToPay, 2) <= 0
                ? $paymentAmount
                : $remainingInterestToPay;

            $paymentLeftForCapital = bcsub($paymentAmount, $interestPaidNow, 2);
            $principalPaidNow = bccomp($paymentLeftForCapital, $remainingPrincipalToPay, 2) <= 0
                ? $paymentLeftForCapital
                : $remainingPrincipalToPay;

            $newInterestPaid = bcadd($interestAlreadyPaid, $interestPaidNow, 2);
            $newPrincipalPaid = bcadd($principalAlreadyPaid, $principalPaidNow, 2);
            $updatedQuotaDebt = max('0.00', bcsub($pendingDebt, $paymentAmount, 2));
            $status = $contract && $contract->status === ContractStatus::PREVENTA_INACTIVA
                ? AmortizationStatus::PARTIAL
                : AmortizationStatus::OVERDUE;

            return [
                'status' => $status,
                'remaining_balance' => $previousRemainingBalance,
                'quota_debt' => $updatedQuotaDebt,
                'interest_paid' => $newInterestPaid,
                'principal_paid' => $newPrincipalPaid,
                'excedente' => '0.00',
            ];
        }

        if (bccomp($paymentAmount, $pendingDebt, 2) === 0) {
            return [
                'status' => AmortizationStatus::PAID,
                'remaining_balance' => $previousRemainingBalance,
                'quota_debt' => '0.00',
                'interest_paid' => $interestValue,
                'principal_paid' => $principalValue,
                'excedente' => '0.00',
            ];
        }

        $surplus = bcsub($paymentAmount, $pendingDebt, 2);

        return [
            'status' => AmortizationStatus::PAID,
            'remaining_balance' => max('0.00', bcsub($previousRemainingBalance, $surplus, 2)),
            'quota_debt' => '0.00',
            'interest_paid' => $interestValue,
            'principal_paid' => $principalValue,
            'excedente' => $surplus,
        ];
    }

    public function registerDownPayment(CreateTransactionDTO $dto): Transaction
    {
        return $this->downPaymentService->registerDownPayment($dto);
    }
}
