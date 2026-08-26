<?php

namespace App\Services;

use App\DTOs\ContractPaymentPromiseDTO;
use App\Models\Contract;
use App\Models\ContractPaymentPromise;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ContractPaymentPromiseService
{
    public function storeCommercialPlan(int $contractId, array $promisesDTOs): Collection
    {
        $contract = Contract::query()->findOrFail($contractId);

        $payload = [];

        foreach ($promisesDTOs as $promiseDTO) {
            if (! $promiseDTO instanceof ContractPaymentPromiseDTO) {
                continue;
            }

            $payload[] = [
                'contract_id' => $contract->id,
                'payment_number' => $promiseDTO->payment_number,
                'expected_date' => $promiseDTO->expected_date,
                'expected_amount' => number_format($promiseDTO->expected_amount, 2, '.', ''),
                'description' => $promiseDTO->description,
                'is_paid' => false,
            ];
        }

        if ($payload === []) {
            return new Collection();
        }

        return DB::transaction(function () use ($contract, $payload) {
            $contract->paymentPromises()->delete();

            $contract->paymentPromises()->createMany($payload);

            return $contract->paymentPromises()->orderBy('payment_number')->get();
        });
    }
}
