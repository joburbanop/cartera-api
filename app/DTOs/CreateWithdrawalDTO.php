<?php

namespace App\DTOs;

use App\Enums\WithdrawalCause;
use Illuminate\Http\Request;

class CreateWithdrawalDTO
{
    public function __construct(
        public readonly int $contractId,
        public readonly string $requestDate,
        public readonly WithdrawalCause $cause,
        public readonly ?float $retentionPercentage,
        public readonly ?string $observations,
        public readonly ?string $modificationJustification,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            contractId: (int) $request->validated('contract_id'),
            requestDate: $request->validated('request_date'),
            cause: WithdrawalCause::from($request->validated('cause')),
            retentionPercentage: $request->validated('retention_percentage') !== null
                ? (float) $request->validated('retention_percentage')
                : null,
            observations: $request->validated('observations'),
            modificationJustification: $request->validated('modification_justification'),
        );
    }
}