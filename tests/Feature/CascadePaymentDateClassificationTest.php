<?php

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Collection\CascadeCollectionService;
use App\Services\Financial\Transaction\InstallmentPaymentAllocator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Hoy = 10 sep. La #2 (vence 5 sep) ES mora hoy, pero el 27 ago todavía no.
 * La #1 (vence 20 ago) es mora en ambas fechas.
 */
function paymentDateAsOfContract(): Contract
{
    $suffix = (string) random_int(100000, 999999);

    $project = Project::query()->create([
        'name' => 'Proyecto AsOf '.$suffix,
        'description' => 'Hueco #4',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => 'AF-'.$suffix,
    ]);

    $contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'sale_price' => '2000.00',
        'down_payment_pactada' => '0.00',
        'term_months' => 2,
        'interest_rate' => 0,
        'status' => 'activo',
        'start_date' => '2026-07-20',
        'first_installment_date' => '2026-08-20',
        'regular_payment_start_date' => '2026-08-20',
    ]);

    foreach ([
        1 => ['due' => '2026-08-20', 'remaining' => '2000.00'],
        2 => ['due' => '2026-09-05', 'remaining' => '1000.00'],
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

    return $contract->fresh();
}

function paymentDateAsOfRow(Contract $contract, int $number): AmortizationInstallment
{
    return $contract->amortizationInstallments()->where('installment_number', $number)->firstOrFail();
}

it('la cola de mora/corriente usa la fecha del pago, no hoy', function () {
    $contract = paymentDateAsOfContract();
    $allocator = app(InstallmentPaymentAllocator::class);
    $paymentDate = '2026-08-27';

    expect($allocator->unpaidOverdueInstallments($contract)->pluck('installment_number')->all())->toBe([1, 2])
        ->and($allocator->unpaidOverdueInstallments($contract, $paymentDate)->pluck('installment_number')->all())->toBe([1])
        ->and($allocator->unpaidCurrentInstallments($contract, $paymentDate))->toHaveCount(0)
        ->and($allocator->resolveInstallmentsToProcess($contract, [], $paymentDate)->pluck('installment_number')->all())->toBe([1])
        ->and($allocator->resolvePartialStatus(paymentDateAsOfRow($contract, 2), $contract))->toBe(AmortizationStatus::OVERDUE)
        ->and($allocator->resolvePartialStatus(paymentDateAsOfRow($contract, 2), $contract, $paymentDate))->toBe(AmortizationStatus::PARTIAL);
});

it('un cobro retroactivo no trata como mora una cuota que a esa fecha aún no vencía', function () {
    $contract = paymentDateAsOfContract();

    try {
        app(CascadeCollectionService::class)->process(
            $contract->id,
            '2000.00',
            null,
            Carbon::parse('2026-08-27'),
        );
        expect(false)->toBeTrue();
    } catch (ValidationException $e) {
        expect($e->errors()['payment_option'][0] ?? '')->toBe(CascadeCollectionService::SURPLUS_ACTION_REQUIRED);
    }

    // La transacción hace rollback al 422: ninguna cuota queda cobrada.
    // Si el motor hubiera usado hoy, #1 y #2 serían mora y los $2.000 se aplicarían.
    expect(paymentDateAsOfRow($contract, 1)->fresh()->status)->toBe(AmortizationStatus::PENDING)
        ->and((string) paymentDateAsOfRow($contract, 1)->fresh()->quota_debt)->toBe('1000.00')
        ->and(paymentDateAsOfRow($contract, 2)->fresh()->status)->toBe(AmortizationStatus::PENDING)
        ->and((string) paymentDateAsOfRow($contract, 2)->fresh()->quota_debt)->toBe('1000.00');
});

it('el cobro retroactivo sí cubre la mora que ya existía a la fecha del pago', function () {
    $contract = paymentDateAsOfContract();

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '1000.00',
        null,
        Carbon::parse('2026-08-27'),
    );

    expect($result['amount_applied'])->toBe('1000.00')
        ->and($result['installments'])->toHaveCount(1)
        ->and($result['installments'][0]['installment_number'])->toBe(1)
        ->and(paymentDateAsOfRow($contract, 1)->fresh()->status)->toBe(AmortizationStatus::PAID)
        ->and(paymentDateAsOfRow($contract, 2)->fresh()->status)->toBe(AmortizationStatus::PENDING)
        ->and((string) paymentDateAsOfRow($contract, 2)->fresh()->quota_debt)->toBe('1000.00');
});

it('el mismo día de vencimiento sigue siendo corriente aunque el pago sea histórico', function () {
    $contract = paymentDateAsOfContract();
    paymentDateAsOfRow($contract, 1)->update(['due_date' => '2026-08-27']);

    $queue = app(InstallmentPaymentAllocator::class)
        ->resolveInstallmentsToProcess($contract, [], '2026-08-27');

    expect($queue->pluck('installment_number')->all())->toBe([1]);
});
