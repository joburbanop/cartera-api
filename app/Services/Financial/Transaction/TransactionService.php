<?php

namespace App\Services\Financial\Transaction;

use App\DTOs\CreateTransactionDTO;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use App\Services\Financial\Transaction\RegularPayment\RegularPaymentService;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function __construct(
        private DownPaymentService $downPaymentService,
        private RegularPaymentService $regularPaymentService
    ) {}

    public function register(CreateTransactionDTO $dto): Transaction
    {
        return match ($dto->transactionType) {

            TransactionType::DOWN_PAYMENT =>
                $this->downPaymentService->registerDownPayment($dto),

            TransactionType::REGULAR_PAYMENT =>
                $this->regularPaymentService->registerRegularPayment($dto),

            default => throw ValidationException::withMessages([
                'transaction_type' =>
                    'Este tipo de transacción todavía no está implementado.',
            ]),
        };
    }
}