<?php

namespace App\DTOs;

use App\Http\Requests\UpdateProjectRequest;

class UpdateProjectDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $location,
        public readonly ?string $description,
        public readonly array $bankAccountIds
    ) {}

    public static function fromRequest(UpdateProjectRequest $request): self
    {
        return new self(
            name: $request->validated('name'),
            location: $request->validated('location'),
            description: $request->validated('description'),
            bankAccountIds: $request->validated('bank_account_ids')
        );
    }
}