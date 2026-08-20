<?php

namespace App\DTOs;

use App\Http\Requests\StoreCustomerRequest;

class CreateCustomerDTO
{
    public function __construct(
        public readonly string $documentType,
        public readonly string $documentNumber,
        public readonly string $name,
        public readonly string $phone,
        public readonly ?string $email,
        public readonly ?string $address,
        public readonly ?string $city
    ) {}

    public static function fromRequest(StoreCustomerRequest $request): self
    {
        return new self(
            documentType: $request->validated('document_type'),
            documentNumber: $request->validated('document_number'),
            name: $request->validated('name'),
            phone: $request->validated('phone'),
            email: $request->validated('email'),
            address: $request->validated('address'),
            city: $request->validated('city')
        );
    }
}