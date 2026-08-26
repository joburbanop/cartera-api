<?php

namespace App\DTOs;

readonly class ContractPaymentPromiseDTO
{
    public function __construct(
        public int $payment_number,
        public string $expected_date,
        public float $expected_amount,
        public ?string $description = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            payment_number: (int) $data['payment_number'],
            expected_date: (string) $data['expected_date'],
            expected_amount: (float) $data['expected_amount'],
            description: $data['description'] ?? null,
        );
    }
}
