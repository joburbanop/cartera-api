<?php

namespace App\Services\Financial\Transaction;

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Transaction;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use App\Services\Financial\Transaction\RegularPayment\RegularPaymentService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function __construct(
        private DownPaymentService $downPaymentService,
        private RegularPaymentService $regularPaymentService,
        private ExtraordinaryPaymentService $extraordinaryPaymentService,
    ) {}

    private function resolvePartialStatus(AmortizationInstallment $plan, ?Contract $contract = null): AmortizationStatus
    {
        if ($contract && $contract->status === ContractStatus::PREVENTA_INACTIVA) {
            return AmortizationStatus::PARTIAL;
        }

        $dueDate = $plan->due_date ?? null;

        if (! $dueDate) {
            return AmortizationStatus::OVERDUE;
        }

        return Carbon::parse($dueDate)->startOfDay()->lt(now()->startOfDay())
            ? AmortizationStatus::OVERDUE
            : AmortizationStatus::PARTIAL;
    }

    private function normalizeSurplus(string $surplus): string
    {
        if (bccomp($surplus, '0.00', 2) <= 0) {
            return '0.00';
        }

        return bccomp($surplus, '2.00', 2) <= 0 ? '0.00' : $surplus;
    }

    public function calculatePaymentImpactForInstallment(
        AmortizationInstallment $plan,
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

            if (bccomp($updatedQuotaDebt, '500.00', 2) < 0) {
                return [
                    'status' => AmortizationStatus::PAID,
                    'quota_debt' => '0.00',
                    'interest_paid' => $interestValue,
                    'principal_paid' => $principalValue,
                    'excedente' => '0.00',
                    'remaining_balance' => $projectedBalance,
                ];
            }

            $status = $this->resolvePartialStatus($plan, $contract);

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

        $surplus = $this->normalizeSurplus(bcsub($paymentAmount, $pendingDebt, 2));
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
        \Log::info('FECHA RECIBIDA EN BACKEND (TransactionService::register): ', [
            'date' => $dto->transactionDate->toDateString(),
            'raw_iso' => $dto->transactionDate->toIso8601String(),
        ]);

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