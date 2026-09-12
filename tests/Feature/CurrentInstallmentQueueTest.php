<?php

use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Collection\CascadeCollectionService;
use App\Services\Financial\Transaction\InstallmentPaymentAllocator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-09 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function currentQueueContract(string $status = 'activo', bool $withInitial = false): Contract
{
    $suffix = (string) random_int(100000, 999999);

    $project = Project::create([
        'name' => 'Proyecto Corriente '.$suffix,
        'description' => 'Fixture Hueco A',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '8'.$suffix,
        'name' => 'Cliente Corriente '.$suffix,
        'phone' => '300'.$suffix,
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'CR-'.$suffix,
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => $status === ContractStatus::PREVENTA_INACTIVA->value ? 'preventa' : 'disponible',
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-CR-'.$suffix,
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 4000,
        'down_payment_pactada' => $withInitial ? 1000 : 0,
        'term_months' => 3,
        'interest_rate' => 0,
        'start_date' => '2026-06-09',
        'initial_payment_date' => '2026-06-09',
        'first_installment_date' => '2026-07-09',
        'regular_payment_start_date' => '2026-07-09',
        'preventa_installments_count' => 0,
        'status' => $status,
    ]);

    if ($withInitial) {
        $contract->amortizationInstallments()->create([
            'contract_id' => $contract->id,
            'installment_number' => 0,
            'due_date' => '2026-06-09',
            'installment_value' => '1000.00',
            'principal_value' => '1000.00',
            'interest_value' => '0.00',
            'extra_payment' => '0.00',
            'remaining_balance' => '4000.00',
            'projected_balance' => '4000.00',
            'interest_paid' => '0.00',
            'principal_paid' => '0.00',
            'quota_debt' => '1000.00',
            'status' => AmortizationStatus::PENDING->value,
        ]);
    }

    foreach ([
        1 => ['due' => '2026-08-09', 'remaining' => '3000.00'],
        2 => ['due' => '2026-09-15', 'remaining' => '2000.00'],
        3 => ['due' => '2026-10-15', 'remaining' => '1000.00'],
    ] as $number => $row) {
        $contract->amortizationInstallments()->create([
            'contract_id' => $contract->id,
            'installment_number' => $number,
            'due_date' => $row['due'],
            'installment_value' => '1000.00',
            'principal_value' => '1000.00',
            'interest_value' => '0.00',
            'extra_payment' => '0.00',
            'remaining_balance' => $row['remaining'],
            'projected_balance' => $row['remaining'],
            'interest_paid' => '0.00',
            'principal_paid' => '0.00',
            'quota_debt' => '1000.00',
            'status' => AmortizationStatus::PENDING->value,
        ]);
    }

    return $contract;
}

function currentQueueRow(Contract $contract, int $number): AmortizationInstallment
{
    return $contract->amortizationInstallments()->where('installment_number', $number)->firstOrFail();
}

it('la cola es mora, luego corriente, luego la futura seleccionada', function () {
    $contract = currentQueueContract();
    $future = currentQueueRow($contract, 3);

    $queue = app(InstallmentPaymentAllocator::class)
        ->resolveInstallmentsToProcess($contract, [(int) $future->id]);

    expect($queue->pluck('installment_number')->all())->toBe([1, 2, 3]);
});

it('en preventa con inicial abierta no fuerza la cuota corriente', function () {
    $contract = currentQueueContract(ContractStatus::PREVENTA_INACTIVA->value, withInitial: true);
    $future = currentQueueRow($contract, 3);

    $allocator = app(InstallmentPaymentAllocator::class);

    expect($allocator->unpaidCurrentInstallments($contract))->toHaveCount(0)
        ->and($allocator->resolveInstallmentsToProcess($contract, [(int) $future->id])->pluck('installment_number')->all())
        ->toBe([3]);
});

it('al seleccionar una futura aplica mora, corriente y luego la seleccionada, y expone application_notice', function () {
    $contract = currentQueueContract();
    $future = currentQueueRow($contract, 3);

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '2500.00',
        null,
        Carbon::parse(now()->toDateString()),
        [(int) $future->id],
    );

    expect($result['amount_applied'])->toBe('2500.00')
        ->and($result['installments'])->toHaveCount(3)
        ->and($result['installments'][0]['installment_number'])->toBe(1)
        ->and($result['installments'][0]['amount_applied'])->toBe('1000.00')
        ->and($result['installments'][1]['installment_number'])->toBe(2)
        ->and($result['installments'][1]['amount_applied'])->toBe('1000.00')
        ->and($result['installments'][2]['installment_number'])->toBe(3)
        ->and($result['installments'][2]['amount_applied'])->toBe('500.00')
        ->and($result['application_notice'])->toBe(
            'Se aplicó $1.000 a la cuota corriente #2 antes que a la cuota #3 que seleccionaste, porque estaba pendiente de este mes.'
        )
        ->and(currentQueueRow($contract, 1)->fresh()->status)->toBe(AmortizationStatus::PAID)
        ->and(currentQueueRow($contract, 2)->fresh()->status)->toBe(AmortizationStatus::PAID)
        ->and(currentQueueRow($contract, 3)->fresh()->quota_debt)->toBe('500.00');
});

it('el sobrante tras la corriente inyectada va a handle y no a la siguiente cuota', function () {
    $contract = currentQueueContract();

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '2374.00',
        'reducir_plazo',
        Carbon::parse(now()->toDateString()),
        [],
    );

    $mora = currentQueueRow($contract, 1)->fresh();
    $current = currentQueueRow($contract, 2)->fresh();
    $future = currentQueueRow($contract, 3)->fresh();

    expect($result['amount_applied'])->toBe('2374.00')
        ->and($mora->status)->toBe(AmortizationStatus::PAID)
        ->and((float) $mora->extra_payment)->toBe(0.0)
        ->and($current->status)->toBe(AmortizationStatus::PAID)
        ->and($current->extra_payment)->toBe('374.00')
        ->and($future->status)->toBe(AmortizationStatus::PENDING)
        ->and($future->quota_debt)->toBe('1000.00')
        ->and((float) $future->extra_payment)->toBe(0.0)
        ->and($result['application_notice'])->toBe(
            'Se aplicó $1.374 a la cuota corriente #2 porque estaba pendiente de este mes.'
        );
});

it('con selección el aviso de Hueco A convive con el extra en la última seleccionada', function () {
    $contract = currentQueueContract();
    $future = currentQueueRow($contract, 3);

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '3374.00',
        'abono_capital',
        Carbon::parse(now()->toDateString()),
        [(int) $future->id],
    );

    $current = currentQueueRow($contract, 2)->fresh();
    $selected = $future->fresh();

    expect($result['application_notice'])->toBe(
        'Se aplicó $1.000 a la cuota corriente #2 antes que a la cuota #3 que seleccionaste, porque estaba pendiente de este mes.'
    )
        ->and((float) $current->extra_payment)->toBe(0.0)
        ->and($selected->status)->toBe(AmortizationStatus::PAID)
        ->and($selected->extra_payment)->toBe('374.00');
});
