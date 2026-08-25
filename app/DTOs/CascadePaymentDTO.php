<?php

namespace App\DTOs;

use App\Http\Requests\StoreCascadePaymentRequest;

class CascadePaymentDTO
{
    public function __construct(
        public readonly int $contractId,
        public readonly string $amount,
    ) {}

    public static function fromRequest(StoreCascadePaymentRequest $request): self
    {
        return new self(
            contractId: (int) $request->validated('contract_id'),
            amount: (string) $request->validated('amount'),
        );
    }
}
