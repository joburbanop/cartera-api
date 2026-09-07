<?php

namespace App\Services\CRM;

use App\DTOs\CreateCustomerDTO;
use App\DTOs\UpdateCustomerDTO;
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
    public function updateCustomer(
    Customer $customer,
    UpdateCustomerDTO $dto,
    int $userId
    ): Customer {
        $data = [];

        if ($dto->has('document_type')) {
            $data['document_type'] = $dto->get('document_type');
        }

        if ($dto->has('document_number')) {
            $data['document_number'] = $dto->get('document_number');
        }

        if ($dto->has('name')) {
            $data['name'] = $dto->get('name');
        }

        if ($dto->has('phone')) {
            $data['phone'] = $dto->get('phone');
        }

        if ($dto->has('email')) {
            $data['email'] = $dto->get('email');
        }

        if ($dto->has('address')) {
            $data['address'] = $dto->get('address');
        }

        if ($dto->has('city')) {
            $data['city'] = $dto->get('city');
        }

        $data['updated_by'] = $userId;

        $customer->update($data);

        return $customer->fresh();
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
    public function getArchivedCustomers(int $perPage = 100)
{
    return Customer::onlyTrashed()
        ->withCount(['activeContracts'])
        ->with($this->listRelations())
        ->latest('deleted_at')
        ->paginate($perPage);
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
    public function archiveCustomer(int $id): void
{
    $customer = Customer::findOrFail($id);

    if ($customer->activeContracts()->exists()) {
        throw new \RuntimeException(
            'No se puede archivar el cliente porque tiene contratos activos.'
        );
    }

    $customer->delete();
}

public function activateCustomer(int $id): void
{
    $customer = Customer::withTrashed()->findOrFail($id);

    if (!$customer->trashed()) {
        throw new \RuntimeException(
            'El cliente ya se encuentra activo.'
        );
    }

    $customer->restore();
}
}