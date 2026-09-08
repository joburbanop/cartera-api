<?php

namespace App\Services;

use App\DTOs\ContractPaymentPromiseDTO;
use App\Enums\PaymentPromiseStatusEnum;
use App\Models\Contract;
use App\Models\ContractPaymentPromise;
use App\Services\Financial\Refinancing\AcuerdoPagoService;
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

            // Los abonos del acuerdo de pago viven en la misma tabla, pero no
            // son el plan comercial. No se reemplazan ni se recrean desde aquí.
            if (trim((string) ($promiseDTO->description ?? '')) === AcuerdoPagoService::DESCRIPTION) {
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
            $contract->paymentPromises()
                ->where(function ($query) {
                    $query->whereNull('description')
                        ->orWhere('description', '!=', AcuerdoPagoService::DESCRIPTION);
                })
                ->delete();

            $contract->paymentPromises()->createMany($payload);
            $this->renumberRefinancingPromisesAfterPlan($contract, $payload);

            return $this->listWithStatus($contract->id);
        });
    }

    /**
     * El plan comercial conserva su numeración 1..N. Los abonos de refinanciación
     * viven en la misma tabla, así que se corren detrás para que no haya dos
     * promesas con el mismo payment_number.
     *
     * @param  list<array<string, mixed>>  $payload
     */
    private function renumberRefinancingPromisesAfterPlan(Contract $contract, array $payload): void
    {
        $next = ((int) max(array_column($payload, 'payment_number'))) + 1;

        $refinancings = $contract->paymentPromises()
            ->where('description', AcuerdoPagoService::DESCRIPTION)
            ->orderBy('expected_date')
            ->orderBy('payment_number')
            ->get();

        foreach ($refinancings as $promise) {
            if ((int) $promise->payment_number !== $next) {
                $promise->update(['payment_number' => $next]);
            }

            $next++;
        }
    }
}
