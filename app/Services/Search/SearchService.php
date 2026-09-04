<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\PermissionName;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\User;

class SearchService
{
    /**
     * @return array{clients: list<array{id: int, name: string, document_number: string}>, contracts: list<array{id: int, contract_number: string, customer_name: string|null}>, lots: list<array{id: int, number: string, project_name: string|null, project_id: int|null}>}
     */
    public function search(User $user, string $query): array
    {
        $empty = [
            'clients' => [],
            'contracts' => [],
            'lots' => [],
        ];

        $term = trim($query);
        if (mb_strlen($term) < 2) {
            return $empty;
        }

        $like = '%'.$term.'%';

        if ($this->canSeeClients($user)) {
            $empty['clients'] = Customer::query()
                ->where(function ($builder) use ($like) {
                    $builder->where('name', 'like', $like)
                        ->orWhere('document_number', 'like', $like);
                })
                ->limit(5)
                ->get(['id', 'name', 'document_number'])
                ->map(static fn (Customer $customer): array => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'document_number' => $customer->document_number,
                ])
                ->values()
                ->all();
        }

        if ($this->canSeeContracts($user)) {
            $empty['contracts'] = Contract::query()
                ->with(['customer:id,name', 'customers:id,name'])
                ->where(function ($builder) use ($like) {
                    $builder->where('contract_number', 'like', $like)
                        ->orWhereHas('customer', function ($customer) use ($like) {
                            $customer->where('name', 'like', $like)
                                ->orWhere('document_number', 'like', $like);
                        })
                        ->orWhereHas('customers', function ($holders) use ($like) {
                            $holders->where('customers.name', 'like', $like)
                                ->orWhere('customers.document_number', 'like', $like);
                        });
                })
                ->limit(5)
                ->get(['id', 'contract_number', 'customer_id'])
                ->map(static fn (Contract $contract): array => [
                    'id' => $contract->id,
                    'contract_number' => $contract->contract_number,
                    'customer_name' => $contract->holderDisplayName(),
                ])
                ->values()
                ->all();
        }

        if ($this->canSeeLots($user)) {
            $empty['lots'] = Lot::query()
                ->with('project:id,name')
                ->where(function ($builder) use ($like) {
                    $builder->where('number', 'like', $like)
                        ->orWhereHas('project', function ($project) use ($like) {
                            $project->where('name', 'like', $like);
                        });
                })
                ->limit(5)
                ->get(['id', 'number', 'project_id'])
                ->map(static fn (Lot $lot): array => [
                    'id' => $lot->id,
                    'number' => $lot->number,
                    'project_name' => $lot->project?->name,
                    'project_id' => $lot->project_id,
                ])
                ->values()
                ->all();
        }

        return $empty;
    }

    private function canSeeClients(User $user): bool
    {
        return $user->can('customers.view')
            || $user->can(PermissionName::CUSTOMERS_MANAGE->value);
    }

    private function canSeeContracts(User $user): bool
    {
        return $user->can(PermissionName::CONTRACTS_VIEW->value)
            || $user->can(PermissionName::CONTRACTS_MANAGE->value);
    }

    private function canSeeLots(User $user): bool
    {
        return $user->can(PermissionName::LOTS_VIEW->value)
            || $user->can(PermissionName::LOTS_MANAGE->value);
    }
}
