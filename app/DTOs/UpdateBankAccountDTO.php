<?php

namespace App\DTOs;

use App\Http\Requests\UpdateBankAccountRequest;

class UpdateBankAccountDTO
{
    public function __construct(
        public string $holderName,
        public bool $isActive,
    ) {}

    public static function fromRequest(UpdateBankAccountRequest $request): self
    {
        return new self(
            holderName: $request->string('holder_name')->toString(),
            isActive: $request->boolean('is_active'),
        );
    }
}