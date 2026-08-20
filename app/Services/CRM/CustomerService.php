<?php

namespace App\Services\CRM;

use App\DTOs\CreateCustomerDTO;
use App\Models\Customer;

class CustomerService
{
    public function createCustomer(CreateCustomerDTO $dto, int $userId): Customer
    {
        return Customer::create([
            'document_type' => $dto->documentType,
            'document_number' => $dto->documentNumber,
            'name' => $dto->name,
            'phone' => $dto->phone,
            'email' => $dto->email,
            'address' => $dto->address,
            'city' => $dto->city,
            'created_by' => $userId,
        ]);
    }

    public function getAllCustomers(int $perPage = 15)
    {
        return Customer::latest()->paginate($perPage);
    }
}