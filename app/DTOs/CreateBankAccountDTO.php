<?php

namespace App\DTOs;

use App\Http\Requests\StoreBankAccountRequest;

class CreateBankAccountDTO
{
   
    public function __construct(
        public readonly string $bankName,
        public readonly string $accountNumber,
        public readonly string $accountType,
        public readonly string $holderName 
    ) {}

    public static function fromRequest(StoreBankAccountRequest $request): self
    {
        return new self(
            bankName: $request->validated('bank_name'),
            accountNumber: $request->validated('account_number'),
            accountType: $request->validated('account_type'),
            holderName: $request->validated('holder_name')
        );
    }
}