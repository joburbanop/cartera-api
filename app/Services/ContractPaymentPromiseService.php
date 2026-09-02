<?php

namespace App\Services;

use App\DTOs\ContractPaymentPromiseDTO;
use App\Enums\PaymentPromiseStatusEnum;
use App\Models\Contract;
use App\Models\ContractPaymentPromise;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractPaymentPromiseService
{
    public function __construct(
        private readonly PaymentPromiseStatusService $paymentPromiseStatusService,
    ) {}

    public function listWithStatus(int $contractId): Collection
    {
        $contract = Contract::query()->findOrFail($contractId);

        return $this->paymentPromiseStatusService->decorate(
            $contract,
            $contract->paymentPromises()->orderBy('expected_date')->orderBy('payment_number')->get(),
        );
    }

    public function reorder(int $contractId, array $items): Collection
    {
        $contract = Contract::query()->findOrFail($contractId);
        $current = $this->listWithStatus($contractId)->keyBy('id');

        if (count($items) !== $current->count()) {
            throw ValidationException::withMessages([
                'promises' => 'Debe enviar todas las promesas del contrato, en el nuevo orden.',
            ]);
        }

        $ids = array_map(static fn (array $item) => (int) $item['id'], $items);

        if ($current->keys()->diff($ids)->isNotEmpty() || count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'promises' => 'Todas las promesas deben pertenecer al contrato y no repetirse.',
            ]);
        }

        foreach ($items as $item) {
            $promise = $current->get((int) $item['id']);
            $newDate = \Carbon\Carbon::parse((string) $item['expected_date'])->toDateString();
            $currentDate = $promise->expected_date instanceof \Carbon\Carbon
                ? $promise->expected_date->toDateString()
                : \Carbon\Carbon::parse((string) $promise->expected_date)->toDateString();

            if ($promise->status === PaymentPromiseStatusEnum::PAGADA->value && $newDate !== $currentDate) {
                throw ValidationException::withMessages([
                    'promises' => 'No se puede mover una promesa ya pagada.',
                ]);
            }
        }

        return DB::transaction(function () use ($contract, $items) {
            foreach (array_values($items) as $index => $item) {
                $contract->paymentPromises()
                    ->whereKey((int) $item['id'])
                    ->update([
                        'expected_date' => \Carbon\Carbon::parse((string) $item['expected_date'])->toDateString(),
                        'payment_number' => $index + 1,
                    ]);
            }

            return $this->listWithStatus($contract->id);
        });
    }
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

            return $this->listWithStatus($contract->id);
        });
    }
}
