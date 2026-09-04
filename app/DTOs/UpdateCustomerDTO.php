<?php

namespace App\DTOs;

use App\Http\Requests\UpdateCustomerRequest;

class UpdateCustomerDTO
{
    public function __construct(
        public readonly array $data
    ) {}

    public static function fromRequest(UpdateCustomerRequest $request): self
    {
        return new self(
            data: $request->validated()
        );
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    public function get(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }
}