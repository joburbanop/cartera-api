<?php

namespace App\Services\CRM;

use App\DTOs\CreateCustomerDTO;
use App\Enums\AmortizationStatus;
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

    public function getAllCustomers(int $perPage = 100)
    {
        return Customer::query()
            ->withCount(['activeContracts'])
            ->with($this->listRelations())
            ->latest()
            ->paginate($perPage);
    }

    public function getCustomerDetail(int $id): Customer
    {
        return Customer::query()
            ->withCount(['activeContracts'])
            ->with([
                ...$this->listRelations(),
                'contracts.lot.project',
            ])
            ->findOrFail($id);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function listRelations(): array
    {
        return [
            'activeContracts.lot',
            'activeContracts.installments' => function ($query) {
                $query->whereNotIn('status', [AmortizationStatus::PAID->value])
                    ->whereDate('due_date', '<=', now());
            },
        ];
    }
}