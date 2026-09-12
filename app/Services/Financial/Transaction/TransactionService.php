<?php

namespace App\Services\Financial\Transaction;

use App\DTOs\CreateTransactionDTO;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Transaction;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public const REGULAR_PAYMENT_USE_CASCADE = 'Los pagos regulares se registran en POST /collections/cascade. Esta ruta ya no acepta regular_payment.';

    public const EXTRAORDINARY_PAYMENT_USE_CASCADE = 'Los abonos extraordinarios se registran en POST /collections/cascade. Esta ruta ya no acepta extraordinary_payment.';

    public const REFUND_NOT_ACCEPTED = 'Esta ruta no acepta refund.';

    public function __construct(
        private DownPaymentService $downPaymentService,
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

            TransactionType::REGULAR_PAYMENT => throw ValidationException::withMessages([
                'transaction_type' => self::REGULAR_PAYMENT_USE_CASCADE,
            ]),

            TransactionType::EXTRAORDINARY_PAYMENT => throw ValidationException::withMessages([
                'transaction_type' => self::EXTRAORDINARY_PAYMENT_USE_CASCADE,
            ]),

            TransactionType::REFUND => throw ValidationException::withMessages([
                'transaction_type' => self::REFUND_NOT_ACCEPTED,
            ]),

            default => throw ValidationException::withMessages([
                'transaction_type' =>
                    'Este tipo de transacción todavía no está implementado.',
            ]),
        };
    }
}