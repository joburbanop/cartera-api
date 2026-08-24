<?php

namespace App\Services\Financial\Transaction;

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\AmortizationPlan;
use App\Models\Contract;
use App\Models\Transaction;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use App\Services\Financial\Transaction\RegularPayment\RegularPaymentService;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function __construct(
        private DownPaymentService $downPaymentService,
        private RegularPaymentService $regularPaymentService,
        private ExtraordinaryPaymentService $extraordinaryPaymentService,
    ) {}

    public function calculatePaymentImpactForInstallment(
        AmortizationPlan|AmortizationInstallment $plan,
        string $paymentAmount,
        ?Contract $contract = null,
    ): array {
        $installmentValue = (string) ($plan->installment_value ?? '0.00');
        $interestValue = (string) ($plan->interest_value ?? '0.00');
        $principalValue = (string) ($plan->principal_value ?? bcsub($installmentValue, $interestValue, 2));
        $interestAlreadyPaid = (string) ($plan->interest_paid ?? '0.00');
        $principalAlreadyPaid = (string) ($plan->principal_paid ?? '0.00');
        $currentQuotaDebt = (string) ($plan->quota_debt ?? '0.00');
        $projectedBalance = (string) ($plan->projected_balance ?? $plan->remaining_balance ?? '0.00');

        $pendingDebt = bccomp($currentQuotaDebt, '0.00', 2) > 0
            ? $currentQuotaDebt
            : bcsub($installmentValue, bcadd($interestAlreadyPaid, $principalAlreadyPaid, 2), 2);

        $pendingDebt = max('0.00', $pendingDebt);

        if (bccomp($paymentAmount, $pendingDebt, 2) < 0) {
            $interestPaidNow = bccomp($paymentAmount, bcsub($interestValue, $interestAlreadyPaid, 2), 2) <= 0
                ? $paymentAmount
                : bcsub($interestValue, $interestAlreadyPaid, 2);

            $paymentLeftForCapital = bcsub($paymentAmount, $interestPaidNow, 2);
            $remainingPrincipalToPay = bcsub($principalValue, $principalAlreadyPaid, 2);
            $principalPaidNow = bccomp($paymentLeftForCapital, $remainingPrincipalToPay, 2) <= 0
                ? $paymentLeftForCapital
                : $remainingPrincipalToPay;

            $newInterestPaid = bcadd($interestAlreadyPaid, $interestPaidNow, 2);
            $newPrincipalPaid = bcadd($principalAlreadyPaid, $principalPaidNow, 2);
            $updatedQuotaDebt = max('0.00', bcsub($pendingDebt, $paymentAmount, 2));
            $status = ($contract && $contract->status === \App\Enums\ContractStatus::PREVENTA_INACTIVA)
                ? AmortizationStatus::PARTIAL
                : AmortizationStatus::OVERDUE;

            return [
                'status' => $status,
                'quota_debt' => $updatedQuotaDebt,
                'interest_paid' => $newInterestPaid,
                'principal_paid' => $newPrincipalPaid,
                'excedente' => '0.00',
                'remaining_balance' => $projectedBalance,
            ];
        }

        if (bccomp($paymentAmount, $pendingDebt, 2) === 0) {
            return [
                'status' => AmortizationStatus::PAID,
                'quota_debt' => '0.00',
                'interest_paid' => $interestValue,
                'principal_paid' => $principalValue,
                'excedente' => '0.00',
                'remaining_balance' => $projectedBalance,
            ];
        }

        $surplus = bcsub($paymentAmount, $pendingDebt, 2);
        $newBalance = max('0.00', bcsub($projectedBalance, $surplus, 2));

        return [
            'status' => AmortizationStatus::PAID,
            'quota_debt' => '0.00',
            'interest_paid' => $interestValue,
            'principal_paid' => $principalValue,
            'excedente' => $surplus,
            'remaining_balance' => $newBalance,
        ];
    }

    public function register(CreateTransactionDTO $dto): Transaction
    {
        return match ($dto->transactionType) {

            TransactionType::DOWN_PAYMENT =>
                $this->downPaymentService->registerDownPayment($dto),

            TransactionType::REGULAR_PAYMENT =>
                $this->regularPaymentService->registerRegularPayment($dto),

            TransactionType::EXTRAORDINARY_PAYMENT =>
                $this->extraordinaryPaymentService->registerExtraordinaryPayment($dto),

            default => throw ValidationException::withMessages([
                'transaction_type' =>
                    'Este tipo de transacción todavía no está implementado.',
            ]),
        };
    }
}