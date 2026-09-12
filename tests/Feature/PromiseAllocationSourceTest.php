<?php

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\ContractPaymentPromise;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\PaymentPromiseAllocation;
use App\Models\Project;
use App\Models\Transaction;
use App\Services\Collection\AllocationSourcePresenter;
use App\Services\PaymentPromiseStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function promiseSourceContract(): Contract
{
    $project = Project::query()->create([
        'name' => 'Proyecto Promesa Sources',
        'description' => 'Fixture',
        'location' => 'Cali',
        'status' => 'active',
    ]);

    return Contract::factory()->create([
        'customer_id' => Customer::factory()->create()->id,
        'lot_id' => Lot::factory()->create(['project_id' => $project->id])->id,
        'is_custom_plan' => true,
        'status' => 'activo',
    ]);
}

it('en promesa el primer allocation es origen: also_applied_to ahí y came_from en el destino', function () {
    $contract = promiseSourceContract();
    $promise1 = ContractPaymentPromise::query()->create([
        'contract_id' => $contract->id,
        'payment_number' => 1,
        'expected_date' => '2026-02-05',
        'expected_amount' => '1000.00',
        'description' => 'Promesa 1',
        'is_paid' => false,
    ]);
    $promise2 = ContractPaymentPromise::query()->create([
        'contract_id' => $contract->id,
        'payment_number' => 2,
        'expected_date' => '2026-03-05',
        'expected_amount' => '1000.00',
        'description' => 'Promesa 2',
        'is_paid' => false,
    ]);

    $tx = Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => '1500.00',
        'transaction_date' => '2026-02-06',
        'payment_method' => PaymentMethod::CASH,
        'notes' => 'Recibo # 0901',
    ]);

    $originAlloc = PaymentPromiseAllocation::query()->create([
        'transaction_id' => $tx->id,
        'payment_promise_id' => $promise1->id,
        'amount' => '1000.00',
    ]);
    PaymentPromiseAllocation::query()->create([
        'transaction_id' => $tx->id,
        'payment_promise_id' => $promise2->id,
        'amount' => '500.00',
    ]);

    expect($originAlloc->id)->toBeLessThan(
        (int) PaymentPromiseAllocation::query()->where('payment_promise_id', $promise2->id)->value('id')
    );

    $promises = $contract->paymentPromises()->orderBy('payment_number')->get();
    $decorated = app(PaymentPromiseStatusService::class)->decorate($contract, $promises);

    $sources1 = $decorated->firstWhere('id', $promise1->id)->sources;
    $sources2 = $decorated->firstWhere('id', $promise2->id)->sources;

    expect($sources1)->toHaveCount(1)
        ->and($sources2)->toHaveCount(1)
        ->and($sources1[0]['also_applied_to'][0]['target_label'])->toBe('Promesa #2')
        ->and($sources1[0]['also_applied_to'][0]['installment_number'])->toBe(2)
        ->and($sources1[0]['also_applied_to'][0]['amount'])->toBe('500.00')
        ->and($sources1[0]['came_from'])->toBe([])
        ->and($sources2[0]['also_applied_to'])->toBe([])
        ->and($sources2[0]['came_from'][0]['target_label'])->toBe('Promesa #1')
        ->and($sources2[0]['came_from'][0]['installment_number'])->toBe(1)
        ->and($sources2[0]['came_from'][0]['amount'])->toBe('500.00')
        ->and($sources1[0]['receipt_number'])->toBe('0901');
});

it('nunca pone also_applied_to y came_from en la misma fila de promesa', function () {
    $contract = promiseSourceContract();
    $first = ContractPaymentPromise::query()->create([
        'contract_id' => $contract->id,
        'payment_number' => 4,
        'expected_date' => '2026-05-05',
        'expected_amount' => '800.00',
        'description' => 'Origen',
        'is_paid' => false,
    ]);
    $second = ContractPaymentPromise::query()->create([
        'contract_id' => $contract->id,
        'payment_number' => 3,
        'expected_date' => '2026-04-05',
        'expected_amount' => '800.00',
        'description' => 'Destino',
        'is_paid' => false,
    ]);

    $tx = Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => '1200.00',
        'transaction_date' => '2026-04-06',
        'payment_method' => PaymentMethod::CASH,
    ]);

    PaymentPromiseAllocation::query()->create([
        'transaction_id' => $tx->id,
        'payment_promise_id' => $first->id,
        'amount' => '800.00',
    ]);
    PaymentPromiseAllocation::query()->create([
        'transaction_id' => $tx->id,
        'payment_promise_id' => $second->id,
        'amount' => '400.00',
    ]);

    $tx->load(['promiseAllocations.promise', 'allocations']);
    $presenter = app(AllocationSourcePresenter::class);

    $originSources = $presenter->forPromise(
        $tx->promiseAllocations->where('payment_promise_id', $first->id)->values(),
        (int) $first->id,
    );
    $destSources = $presenter->forPromise(
        $tx->promiseAllocations->where('payment_promise_id', $second->id)->values(),
        (int) $second->id,
    );

    expect($originSources[0]['came_from'])->toBe([])
        ->and($originSources[0]['also_applied_to'])->not->toBeEmpty()
        ->and($destSources[0]['also_applied_to'])->toBe([])
        ->and($destSources[0]['came_from'][0]['target_label'])->toBe('Promesa #4')
        ->and($destSources[0]['came_from'][0]['amount'])->toBe('400.00');
});

it('excluye allocations de una transacción revertida al armar sources de la promesa', function () {
    $contract = promiseSourceContract();
    $promise = ContractPaymentPromise::query()->create([
        'contract_id' => $contract->id,
        'payment_number' => 1,
        'expected_date' => '2026-02-05',
        'expected_amount' => '1000.00',
        'description' => 'Promesa 1',
        'is_paid' => false,
    ]);

    $reversed = Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => '1000.00',
        'transaction_date' => '2026-02-06',
        'payment_method' => PaymentMethod::CASH,
        'receipt_number' => '8484',
        'reversed_at' => now(),
    ]);
    PaymentPromiseAllocation::query()->create([
        'transaction_id' => $reversed->id,
        'payment_promise_id' => $promise->id,
        'amount' => '1000.00',
    ]);

    $live = Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => '1000.00',
        'transaction_date' => '2026-02-07',
        'payment_method' => PaymentMethod::CASH,
        'receipt_number' => '332',
    ]);
    PaymentPromiseAllocation::query()->create([
        'transaction_id' => $live->id,
        'payment_promise_id' => $promise->id,
        'amount' => '1000.00',
    ]);

    $allocs = PaymentPromiseAllocation::query()
        ->where('payment_promise_id', $promise->id)
        ->with(['transaction.promiseAllocations.promise', 'transaction.allocations'])
        ->orderBy('id')
        ->get();

    $sources = app(AllocationSourcePresenter::class)->forPromise($allocs, (int) $promise->id);

    expect($allocs)->toHaveCount(2)
        ->and($sources)->toHaveCount(1)
        ->and($sources[0]['receipt_number'])->toBe('332')
        ->and($sources[0]['transaction_id'])->toBe($live->id)
        ->and($sources[0]['amount'])->toBe('1000.00');
});
