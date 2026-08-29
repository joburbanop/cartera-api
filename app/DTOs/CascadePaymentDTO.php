<?php

namespace App\DTOs;

use App\Http\Requests\StoreCascadePaymentRequest;
use Illuminate\Http\UploadedFile;

class CascadePaymentDTO
{
    public function __construct(
        public readonly int $contractId,
        public readonly string $amount,
        public readonly ?string $paymentOption,
        public readonly ?UploadedFile $receipt,
    ) {}

    public static function fromRequest(StoreCascadePaymentRequest $request): self
    {
        return new self(
            contractId: (int) $request->validated('contract_id'),
            amount: (string) $request->validated('amount'),
            paymentOption: $request->input('payment_option'),
            receipt: $request->file('receipt'),
        );
    }
}
