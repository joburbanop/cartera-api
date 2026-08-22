<?php

namespace App\DTOs;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Http\Requests\StoreTransactionRequest;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;

class CreateTransactionDTO
{
    public function __construct(
        public readonly int $contractId,
        public readonly string $amount,
        public readonly Carbon $transactionDate,
        public readonly PaymentMethod $paymentMethod,
        public readonly TransactionType $transactionType,
        public readonly array $installmentNumbers,
        public readonly UploadedFile $receipt,
    ) {}

    public static function fromRequest(
        StoreTransactionRequest $request,
        int $contractId
    ): self {
        return new self(
            contractId: $contractId,
            amount: $request->validated('amount'),
            transactionDate: Carbon::parse($request->validated('transaction_date')),
            paymentMethod: PaymentMethod::from(
                $request->validated('payment_method')
            ),
            transactionType: TransactionType::from(
                $request->validated('transaction_type', TransactionType::DOWN_PAYMENT->value)
            ),
            installmentNumbers: array_values(array_map('intval', $request->validated('installment_numbers', []))),
            receipt: $request->file('receipt'),
        );
    }
}