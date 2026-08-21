<?php

namespace App\DTOs;

use App\Enums\PaymentMethod;
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
            receipt: $request->file('receipt'),
        );
    }
}