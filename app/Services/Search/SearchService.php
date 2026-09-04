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
     * @return array{clients: list<array{id: int, name: string, document_number: string}>, contracts: list<array{id: int, contract_number: string, customer_name: string|null}>, lots: list<array{id: int, number: string, project_name: string|null, project_id: int|null, contract_id: int|null}>}
     */
    public function search(User $user, string $query): array
    {
        $empty = [
            'clients' => [],
            'contracts' => [],
            'lots' => [],
        ];

        $term = trim($query);
        if ($term === '') {
            return $empty;
        }

        $like = '%'.$term.'%';

        if (mb_strlen($term) >= 2 && $this->canSeeClients($user)) {
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

        if (mb_strlen($term) >= 2 && $this->canSeeContracts($user)) {
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
            $empty['lots'] = $this->searchLots($term, $like);
        }

        return $empty;
    }

    /**
     * @return list<array{id: int, number: string, project_name: string|null, project_id: int|null, contract_id: int|null}>
     */
    private function searchLots(string $term, string $like): array
    {
        $candidates = $this->lotNumberCandidates($term);

        $lots = Lot::query()
            ->with(['project:id,name', 'contracts' => function ($relation): void {
                $relation->select('id', 'lot_id')->orderByDesc('id');
            }])
            ->where(function ($builder) use ($candidates, $term, $like) {
                $builder->where(function ($exact) use ($candidates) {
                    foreach ($candidates as $candidate) {
                        $exact->orWhereRaw('LOWER(number) = ?', [mb_strtolower($candidate)]);
                    }
                });

                if (mb_strlen($term) >= 2) {
                    $builder->orWhere('number', 'like', $like)
                        ->orWhereHas('project', function ($project) use ($like) {
                            $project->where('name', 'like', $like);
                        });
                }
            })
            ->limit(5)
            ->get(['id', 'number', 'project_id']);

        return $lots->map(static fn (Lot $lot): array => [
            'id' => $lot->id,
            'number' => $lot->number,
            'project_name' => $lot->project?->name,
            'project_id' => $lot->project_id,
            'contract_id' => $lot->contracts->first()?->id,
        ])->values()->all();
    }

    /**
     * @return list<string>
     */
    public function lotNumberCandidates(string $term): array
    {
        $raw = trim($term);
        $stripped = trim((string) preg_replace('/^(lote|l)[\s.:\-]*/iu', '', $raw));
        $candidates = array_filter([$raw, $stripped], static fn (string $value): bool => $value !== '');

        if ($stripped !== '' && preg_match('/^\d+[A-Za-z]?$/', $stripped)) {
            $candidates[] = 'L-'.$stripped;
            $candidates[] = 'Lote '.$stripped;
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $unique[mb_strtolower($candidate)] = $candidate;
        }

        return array_values($unique);
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
