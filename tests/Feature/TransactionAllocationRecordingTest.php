<?php

use App\DTOs\CreateTransactionDTO;
use App\Enums\AllocationTarget;
use App\Enums\AmortizationStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\ContractPaymentPromise;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\PaymentPromiseAllocation;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Services\Collection\AllocationSourcePresenter;
use App\Services\Collection\CascadeCollectionService;
use App\Services\Financial\LifeSheet\ContractLifeSheetService;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use App\Services\PaymentPromiseStatusService;
use App\Support\DownPaymentLedger;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function allocationContract(): Contract
{
    $project = Project::query()->create([
        'name' => 'Proyecto Alloc',
        'description' => 'Fixture allocations',
        'location' => 'Cali',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => '88',
    ]);

    $contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'sale_price' => '10000000.00',
        'down_payment_pactada' => '2000000.00',
        'term_months' => 12,
        'interest_rate' => '1.00',
        'status' => 'activo',
        'is_custom_plan' => true,
    ]);

    AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 0,
        'due_date' => '2026-01-05',
        'installment_value' => '2000000.00',
        'extra_payment' => '0.00',
        'interest_value' => '0.00',
        'principal_value' => '2000000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '2000000.00',
        'remaining_balance' => '8000000.00',
        'projected_balance' => '8000000.00',
        'status' => AmortizationStatus::PENDING,
    ]);
    AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 1,
        'due_date' => '2026-02-05',
        'installment_value' => '1000.00',
        'extra_payment' => '0.00',
        'interest_value' => '200.00',
        'principal_value' => '800.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000.00',
        'remaining_balance' => '8000000.00',
        'projected_balance' => '8000000.00',
        'status' => AmortizationStatus::PENDING,
    ]);
    AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 2,
        'due_date' => '2026-03-05',
        'installment_value' => '1000.00',
        'extra_payment' => '0.00',
        'interest_value' => '200.00',
        'principal_value' => '800.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000.00',
        'remaining_balance' => '7000000.00',
        'projected_balance' => '7000000.00',
        'status' => AmortizationStatus::PENDING,
    ]);

    return $contract;
}

function closeInitialInstallment(Contract $contract): void
{
    $contract->amortizationInstallments()
        ->where('installment_number', 0)
        ->update([
            'interest_paid' => '0.00',
            'principal_paid' => '2000000.00',
            'quota_debt' => '0.00',
            'status' => AmortizationStatus::PAID,
        ]);
}

function allocationPromises(Contract $contract): array
{
    return [
        ContractPaymentPromise::query()->create([
            'contract_id' => $contract->id,
            'payment_number' => 1,
            'expected_date' => '2026-02-05',
            'expected_amount' => '1000.00',
            'description' => 'Pago 1',
            'is_paid' => false,
        ]),
        ContractPaymentPromise::query()->create([
            'contract_id' => $contract->id,
            'payment_number' => 2,
            'expected_date' => '2026-03-05',
            'expected_amount' => '1000.00',
            'description' => 'Pago 2',
            'is_paid' => false,
        ]),
    ];
}

it('un down_payment escribe allocation a la cuota inicial y el ledger no duplica', function () {
    $contract = allocationContract();

    app(DownPaymentService::class)->registerDownPayment(new CreateTransactionDTO(
        contractId: $contract->id,
        amount: '500000.00',
        transactionDate: Carbon::parse('2026-01-10'),
        paymentMethod: PaymentMethod::CASH,
        transactionType: TransactionType::DOWN_PAYMENT,
        installmentNumbers: [],
    ));

    $tx = $contract->transactions()->firstOrFail();
    $allocation = $tx->allocations()->where('target', AllocationTarget::DOWN_PAYMENT)->first();

    expect($allocation)->not->toBeNull()
        ->and((float) $allocation->amount)->toBe(500000.0)
        ->and((float) DownPaymentLedger::collected($contract->fresh()))->toBe(500000.0);
});

it('la cascada deja rastro pago→cuota en transaction_allocations', function () {
    $contract = allocationContract();
    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '1000.00',
        'adelantar_cuotas',
        Carbon::parse('2026-02-10'),
        [$cuota1->id],
    );

    $tx = Transaction::query()->findOrFail($result['transaction_id']);
    $allocations = $tx->allocations;

    expect($allocations)->toHaveCount(1)
        ->and($allocations->first()->target)->toBe(AllocationTarget::INSTALLMENT)
        ->and((int) $allocations->first()->amortization_installment_id)->toBe($cuota1->id)
        ->and((float) $allocations->first()->amount)->toBe(1000.0)
        ->and((float) $allocations->first()->interest)->toBe(200.0)
        ->and((float) $allocations->first()->principal)->toBe(800.0);
});

it('un mixto no cuenta la parte a inicial como plata de promesa', function () {
    $contract = allocationContract();
    ContractPaymentPromise::query()->create([
        'contract_id' => $contract->id,
        'payment_number' => 1,
        'expected_date' => '2026-02-05',
        'expected_amount' => '1500000.00',
        'description' => 'Pago 1',
        'is_paid' => false,
    ]);

    TransactionAllocation::query()->create([
        'transaction_id' => Transaction::query()->create([
            'contract_id' => $contract->id,
            'transaction_type' => TransactionType::SPLIT_PAYMENT,
            'amount' => '5500000.00',
            'transaction_date' => '2026-02-10',
            'payment_method' => PaymentMethod::CASH,
        ])->id,
        'target' => AllocationTarget::DOWN_PAYMENT,
        'amortization_installment_id' => $contract->amortizationInstallments()->where('installment_number', 0)->value('id'),
        'amount' => '3000000.00',
        'principal' => '3000000.00',
        'interest' => '0.00',
    ]);

    $tx = $contract->transactions()->firstOrFail();
    TransactionAllocation::query()->create([
        'transaction_id' => $tx->id,
        'target' => AllocationTarget::INSTALLMENT,
        'amortization_installment_id' => $contract->amortizationInstallments()->where('installment_number', 1)->value('id'),
        'amount' => '2500000.00',
        'principal' => '1500000.00',
        'interest' => '1000000.00',
    ]);

    $collected = app(PaymentPromiseStatusService::class)->regularCollected($contract->fresh());

    expect((float) $collected)->toBe(2500000.0);
});

it('un cobro nuevo anota contra la promesa abierta, sin inventar las históricas', function () {
    $contract = allocationContract();
    $promise1 = ContractPaymentPromise::query()->create([
        'contract_id' => $contract->id,
        'payment_number' => 1,
        'expected_date' => '2026-02-05',
        'expected_amount' => '1000.00',
        'description' => 'Pago 1',
        'is_paid' => false,
    ]);
    $promise2 = ContractPaymentPromise::query()->create([
        'contract_id' => $contract->id,
        'payment_number' => 2,
        'expected_date' => '2026-03-05',
        'expected_amount' => '1000.00',
        'description' => 'Pago 2',
        'is_paid' => false,
    ]);

    Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => '1000.00',
        'transaction_date' => '2026-02-06',
        'payment_method' => PaymentMethod::CASH,
    ]);

    expect(PaymentPromiseAllocation::query()->count())->toBe(0);

    $cuota2 = $contract->amortizationInstallments()->where('installment_number', 2)->firstOrFail();
    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '1000.00',
        'adelantar_cuotas',
        Carbon::parse('2026-03-10'),
        [$cuota2->id],
    );

    $links = PaymentPromiseAllocation::query()->where('transaction_id', $result['transaction_id'])->get();

    expect($links)->toHaveCount(1)
        ->and((int) $links->first()->payment_promise_id)->toBe($promise2->id)
        ->and((float) $links->first()->amount)->toBe(1000.0)
        ->and(PaymentPromiseAllocation::query()->where('payment_promise_id', $promise1->id)->count())->toBe(0);
});

it('un cobro nuevo menor a dos cuotas vencidas deja sources en ambas y el extra a capital no cuelga de un #', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-10 12:00:00'));

    $contract = allocationContract();
    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    $cuota2 = $contract->amortizationInstallments()->where('installment_number', 2)->firstOrFail();

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '1500.00',
        'adelantar_cuotas',
        Carbon::parse('2026-04-10'),
    );

    $tx = Transaction::query()->findOrFail($result['transaction_id']);
    $installmentAllocs = $tx->allocations->where('target', AllocationTarget::INSTALLMENT);

    expect($installmentAllocs)->toHaveCount(2)
        ->and((float) $installmentAllocs->firstWhere('amortization_installment_id', $cuota1->id)->amount)->toBe(1000.0)
        ->and((float) $installmentAllocs->firstWhere('amortization_installment_id', $cuota2->id)->amount)->toBe(500.0)
        ->and($tx->allocations->where('target', AllocationTarget::CAPITAL)->count())->toBe(0);

    $plan = $contract->amortizationInstallments()->orderBy('installment_number')->get();
    app(AllocationSourcePresenter::class)->attachToInstallments($plan);

    $sources1 = $plan->firstWhere('installment_number', 1)->sources;
    $sources2 = $plan->firstWhere('installment_number', 2)->sources;

    expect($plan->firstWhere('installment_number', 0)->sources)->toHaveCount(0)
        ->and($sources1)->toHaveCount(1)
        ->and($sources2)->toHaveCount(1)
        ->and((float) $sources1[0]['amount'])->toBe(1000.0)
        ->and((float) $sources2[0]['amount'])->toBe(500.0)
        ->and($sources1[0]['also_applied_to'][0]['installment_number'])->toBe(2)
        ->and($sources1[0]['also_applied_to'][0]['amount'])->toBe('500.00')
        ->and($sources1[0]['came_from'])->toBe([])
        ->and($sources2[0]['also_applied_to'])->toBe([])
        ->and($sources2[0]['came_from'][0]['installment_number'])->toBe(1)
        ->and($sources2[0]['came_from'][0]['amount'])->toBe('500.00')
        ->and(collect($sources2[0]['route'])->pluck('installment_number')->all())->toBe([1, 2]);
});

it('un cobro que parte en una cuota posterior deja came_from en la que recibió el sobrante', function () {
    $contract = allocationContract();
    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    $cuota2 = $contract->amortizationInstallments()->where('installment_number', 2)->firstOrFail();

    $tx = Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => '1067671.00',
        'transaction_date' => '2026-03-10',
        'payment_method' => PaymentMethod::CASH,
        'notes' => 'Recibo #0999 | Concepto: CUOTA 2',
    ]);

    TransactionAllocation::query()->create([
        'transaction_id' => $tx->id,
        'amortization_installment_id' => $cuota2->id,
        'target' => AllocationTarget::INSTALLMENT,
        'amount' => '1000000.00',
        'principal' => '800000.00',
        'interest' => '200000.00',
    ]);
    TransactionAllocation::query()->create([
        'transaction_id' => $tx->id,
        'amortization_installment_id' => $cuota1->id,
        'target' => AllocationTarget::INSTALLMENT,
        'amount' => '67671.00',
        'principal' => '67671.00',
        'interest' => '0.00',
    ]);

    $plan = $contract->amortizationInstallments()->orderBy('installment_number')->get();
    app(AllocationSourcePresenter::class)->attachToInstallments($plan);

    $sources1 = $plan->firstWhere('installment_number', 1)->sources;
    $sources2 = $plan->firstWhere('installment_number', 2)->sources;

    expect($sources2[0]['also_applied_to'][0]['installment_number'])->toBe(1)
        ->and($sources2[0]['also_applied_to'][0]['amount'])->toBe('67671.00')
        ->and($sources2[0]['came_from'])->toBe([])
        ->and($sources1[0]['also_applied_to'])->toBe([])
        ->and($sources1[0]['came_from'][0]['installment_number'])->toBe(2)
        ->and($sources1[0]['came_from'][0]['amount'])->toBe('67671.00');
});

it('el extra a capital sale como destino sin número de cuota', function () {
    $contract = allocationContract();
    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();

    $tx = Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => '1500.00',
        'transaction_date' => '2026-02-10',
        'payment_method' => PaymentMethod::CASH,
    ]);

    TransactionAllocation::recordInstallmentAndCapital(
        $tx->id,
        $cuota1->id,
        '1500.00',
        '1300.00',
        '200.00',
        '500.00',
    );

    $plan = $contract->amortizationInstallments()->orderBy('installment_number')->get();
    app(AllocationSourcePresenter::class)->attachToInstallments($plan);

    $sources = $plan->firstWhere('installment_number', 1)->sources;
    $capital = collect($sources[0]['also_applied_to'])->firstWhere('target_label', 'Abono a capital');

    expect($tx->allocations->where('target', AllocationTarget::CAPITAL)->first()->amortization_installment_id)->toBeNull()
        ->and($sources)->toHaveCount(1)
        ->and((float) $sources[0]['amount'])->toBe(1000.0)
        ->and($sources[0]['came_from'])->toBe([])
        ->and($capital['installment_number'])->toBeNull()
        ->and($capital['amount'])->toBe('500.00')
        ->and($plan->firstWhere('installment_number', 2)->sources)->toHaveCount(0);
});

it('excluye el pago revertido al armar sources y covered_amount de la cuota', function () {
    $contract = allocationContract();
    $cuota = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();

    $reversed = Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => '1000.00',
        'transaction_date' => '2026-02-10',
        'payment_method' => PaymentMethod::CASH,
        'receipt_number' => '8484',
        'reversed_at' => now(),
    ]);
    TransactionAllocation::query()->create([
        'transaction_id' => $reversed->id,
        'amortization_installment_id' => $cuota->id,
        'target' => AllocationTarget::INSTALLMENT,
        'amount' => '1000.00',
        'principal' => '800.00',
        'interest' => '200.00',
    ]);

    $live = Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => '1000.00',
        'transaction_date' => '2026-02-11',
        'payment_method' => PaymentMethod::CASH,
        'receipt_number' => '332',
    ]);
    TransactionAllocation::query()->create([
        'transaction_id' => $live->id,
        'amortization_installment_id' => $cuota->id,
        'target' => AllocationTarget::INSTALLMENT,
        'amount' => '1000.00',
        'principal' => '800.00',
        'interest' => '200.00',
    ]);

    $cuota->update([
        'interest_paid' => '200.00',
        'principal_paid' => '800.00',
        'quota_debt' => '0.00',
        'status' => AmortizationStatus::PAID,
    ]);

    $plan = $contract->amortizationInstallments()->orderBy('installment_number')->get();
    $presenter = app(AllocationSourcePresenter::class);
    $presenter->attachToInstallments($plan);

    $decorated = $plan->firstWhere('installment_number', 1);
    expect($decorated->sources)->toHaveCount(1)
        ->and($decorated->sources[0]['receipt_number'])->toBe('332')
        ->and($decorated->sources[0]['transaction_id'])->toBe($live->id)
        ->and($decorated->sources[0]['amount'])->toBe('1000.00')
        ->and($decorated->covered_amount)->toBe('1000.00');

    $mixed = TransactionAllocation::query()
        ->where('amortization_installment_id', $cuota->id)
        ->with('transaction')
        ->orderBy('id')
        ->get();

    expect($mixed)->toHaveCount(2)
        ->and($presenter->forInstallment($mixed, (int) $cuota->id))->toHaveCount(1)
        ->and($presenter->forInstallment($mixed, (int) $cuota->id)[0]['receipt_number'])->toBe('332');
});

it('B2+E1: cobro exacto de la primera # la deja pagada, sin capital, y el mismo recibo sale en tabla HV y promesa', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-10 12:00:00'));

    $contract = allocationContract();
    closeInitialInstallment($contract);
    [$promise1, $promise2] = allocationPromises($contract);
    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    $cuota2 = $contract->amortizationInstallments()->where('installment_number', 2)->firstOrFail();

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '1000.00',
        'adelantar_cuotas',
        Carbon::parse('2026-02-10'),
        [],
        null,
        PaymentMethod::CASH,
        'Concepto: CUOTA B2',
        true,
        null,
        'B2E1',
    );

    $tx = Transaction::query()->findOrFail($result['transaction_id']);
    $cuota1->refresh();
    $cuota2->refresh();

    expect($cuota1->status)->toBe(AmortizationStatus::PAID)
        ->and((float) $cuota1->quota_debt)->toBe(0.0)
        ->and($cuota2->status)->not->toBe(AmortizationStatus::PAID)
        ->and((float) $cuota2->quota_debt)->toBe(1000.0)
        ->and($tx->allocations)->toHaveCount(1)
        ->and($tx->allocations->first()->target)->toBe(AllocationTarget::INSTALLMENT)
        ->and((int) $tx->allocations->first()->amortization_installment_id)->toBe($cuota1->id)
        ->and($tx->allocations->where('target', AllocationTarget::CAPITAL)->count())->toBe(0);

    $plan = $contract->amortizationInstallments()->orderBy('installment_number')->get();
    app(AllocationSourcePresenter::class)->attachToInstallments($plan);

    expect($plan->firstWhere('installment_number', 1)->sources)->toHaveCount(1)
        ->and($plan->firstWhere('installment_number', 1)->sources[0]['receipt_number'])->toBe('B2E1')
        ->and((float) $plan->firstWhere('installment_number', 1)->sources[0]['amount'])->toBe(1000.0)
        ->and($plan->firstWhere('installment_number', 1)->sources[0]['also_applied_to'])->toBe([])
        ->and($plan->firstWhere('installment_number', 2)->sources)->toHaveCount(0);

    $sheet = app(ContractLifeSheetService::class)->build($contract->fresh());
    $row = collect($sheet['rows'])->firstWhere('transaction_id', $tx->id);

    expect($row)->not->toBeNull()
        ->and($row['allocations'])->toHaveCount(1)
        ->and($row['allocations'][0]['target'])->toBe(AllocationTarget::INSTALLMENT->value)
        ->and($row['allocations'][0]['installment_number'])->toBe(1)
        ->and($row['allocations'][0]['amount'])->toBe('1000.00');

    $promiseLinks = PaymentPromiseAllocation::query()->where('transaction_id', $tx->id)->get();
    $promises = $contract->paymentPromises()->orderBy('payment_number')->get();
    app(PaymentPromiseStatusService::class)->decorate($contract->fresh(), $promises);

    expect($promiseLinks)->toHaveCount(1)
        ->and((int) $promiseLinks->first()->payment_promise_id)->toBe($promise1->id)
        ->and((float) $promiseLinks->first()->amount)->toBe(1000.0)
        ->and(PaymentPromiseAllocation::query()->where('payment_promise_id', $promise2->id)->count())->toBe(0)
        ->and($promises->firstWhere('id', $promise1->id)->sources)->toHaveCount(1)
        ->and($promises->firstWhere('id', $promise2->id)->sources)->toHaveCount(0);
});

it('B3+E2: cobro mayor a la primera # y menor al plan adelanta sin capital y el recibo cruza las dos #', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-10 12:00:00'));

    $contract = allocationContract();
    closeInitialInstallment($contract);
    [$promise1, $promise2] = allocationPromises($contract);
    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    $cuota2 = $contract->amortizationInstallments()->where('installment_number', 2)->firstOrFail();

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '1500.00',
        'adelantar_cuotas',
        Carbon::parse('2026-04-10'),
        [],
        null,
        PaymentMethod::CASH,
        'Concepto: CUOTA B3',
        true,
        null,
        'B3E2',
    );

    $tx = Transaction::query()->findOrFail($result['transaction_id']);
    $cuota1->refresh();
    $cuota2->refresh();

    expect($cuota1->status)->toBe(AmortizationStatus::PAID)
        ->and($cuota2->status)->not->toBe(AmortizationStatus::PAID)
        ->and((float) $cuota2->quota_debt)->toBe(500.0)
        ->and($tx->allocations->where('target', AllocationTarget::INSTALLMENT))->toHaveCount(2)
        ->and($tx->allocations->where('target', AllocationTarget::CAPITAL)->count())->toBe(0)
        ->and((float) $tx->allocations->sum('amount'))->toBe(1500.0);

    $plan = $contract->amortizationInstallments()->orderBy('installment_number')->get();
    app(AllocationSourcePresenter::class)->attachToInstallments($plan);
    $sources1 = $plan->firstWhere('installment_number', 1)->sources;
    $sources2 = $plan->firstWhere('installment_number', 2)->sources;

    expect($sources1)->toHaveCount(1)
        ->and($sources2)->toHaveCount(1)
        ->and($sources1[0]['receipt_number'])->toBe('B3E2')
        ->and($sources1[0]['also_applied_to'][0]['installment_number'])->toBe(2)
        ->and($sources1[0]['also_applied_to'][0]['amount'])->toBe('500.00')
        ->and($sources2[0]['came_from'][0]['installment_number'])->toBe(1)
        ->and($sources2[0]['came_from'][0]['amount'])->toBe('500.00');

    $sheet = app(ContractLifeSheetService::class)->build($contract->fresh());
    $row = collect($sheet['rows'])->firstWhere('transaction_id', $tx->id);
    $destinations = collect($row['allocations'] ?? []);

    expect($destinations)->toHaveCount(2)
        ->and($destinations->pluck('installment_number')->sort()->values()->all())->toBe([1, 2])
        ->and($destinations->contains('target', AllocationTarget::CAPITAL->value))->toBeFalse();

    $promiseLinks = PaymentPromiseAllocation::query()
        ->where('transaction_id', $tx->id)
        ->orderBy('id')
        ->get();

    expect($promiseLinks)->toHaveCount(2)
        ->and((int) $promiseLinks[0]->payment_promise_id)->toBe($promise1->id)
        ->and((float) $promiseLinks[0]->amount)->toBe(1000.0)
        ->and((int) $promiseLinks[1]->payment_promise_id)->toBe($promise2->id)
        ->and((float) $promiseLinks[1]->amount)->toBe(500.0);
});
