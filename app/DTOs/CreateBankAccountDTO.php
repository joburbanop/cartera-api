<?php

namespace App\DTOs;

use Illuminate\Http\Request;
use App\Enums\BankAccountType;

class CreateBankAccountDTO
{
    public function __construct(
        public readonly string $bankName,
        public readonly string $accountNumber,
        public readonly BankAccountType $accountType, // <-- Ahora exige el Enum
        public readonly string $holderName,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            bankName: $request->validated('bank_name'),
            accountNumber: $request->validated('account_number'),
            // Convertimos el string validado a una instancia del Enum
            accountType: BankAccountType::from($request->validated('account_type')),
            holderName: $request->validated('holder_name'),
        );
    }
}