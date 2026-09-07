<?php

namespace App\Services\Financial\Transaction;

use App\DTOs\CreateTransactionDTO;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
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
        private InstallmentPaymentAllocator $allocator,
    ) {}

    public function calculatePaymentImpactForInstallment(
        AmortizationInstallment $plan,
        string $paymentAmount,
        ?Contract $contract = null,
    ): array {
        $impact = $this->allocator->computeImpact($plan, $paymentAmount, $contract);
        $projectedBalance = (string) ($plan->projected_balance ?? $plan->remaining_balance ?? '0.00');

        return [
            'status' => $impact['status'],
            'quota_debt' => $impact['quota_debt'],
            'interest_paid' => $impact['interest_paid'],
            'principal_paid' => $impact['principal_paid'],
            'excedente' => $impact['excedente'],
            'remaining_balance' => $projectedBalance,
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