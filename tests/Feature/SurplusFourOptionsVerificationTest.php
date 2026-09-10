<?php

use App\Enums\AllocationTarget;
use App\Enums\AmortizationStatus;
use App\Enums\RoleName;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Collection\CascadeCollectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-09 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Cinco cuotas de $1.000 a tasa 0. El pago de $2.500 sobre la #1 deja $1.500
 * de excedente: bastante para acortar el plazo (capital) o bajar la PMT (cuota)
 * o cubrir #2 y parte de #3 (adelanto).
 */
function surplusFourContract(string $suffix): Contract
{
    $project = Project::create([
        'name' => 'Verificacion 4 opciones '.$suffix,
        'description' => 'Fixture',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '88'.$suffix,
        'name' => 'Cliente 4 opciones '.$suffix,
        'phone' => '300'.$suffix,
    ]);
    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'V4-'.$suffix,
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-V4-'.$suffix,
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 5000,
        'down_payment_pactada' => 0,
        'term_months' => 5,
        'interest_rate' => 0,
        'start_date' => '2026-08-15',
        'initial_payment_date' => '2026-08-15',
        'first_installment_date' => '2026-10-15',
        'regular_payment_start_date' => '2026-10-15',
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    foreach ([1, 2, 3, 4, 5] as $n) {
        $contract->amortizationInstallments()->create([
            'contract_id' => $contract->id,
            'installment_number' => $n,
            'due_date' => Carbon::parse('2026-10-15')->addMonthsNoOverflow($n - 1)->toDateString(),
            'installment_value' => '1000.00',
            'principal_value' => '1000.00',
            'interest_value' => '0.00',
            'extra_payment' => '0.00',
            'remaining_balance' => (string) (5000 - ($n * 1000)).'.00',
            'projected_balance' => (string) (5000 - ($n * 1000)).'.00',
            'interest_paid' => '0.00',
            'principal_paid' => '0.00',
            'quota_debt' => '1000.00',
            'status' => AmortizationStatus::PENDING->value,
        ]);
    }

    return $contract->fresh();
}

function surplusSnapshot(Contract $contract): array
{
    $rows = $contract->amortizationInstallments()
        ->where('installment_number', '>', 0)
        ->orderBy('installment_number')
        ->get()
        ->map(fn (AmortizationInstallment $row) => [
            'n' => (int) $row->installment_number,
            'value' => (string) $row->installment_value,
            'extra' => (string) $row->extra_payment,
            'debt' => (string) $row->quota_debt,
            'remaining' => (string) $row->remaining_balance,
            'status' => $row->status instanceof AmortizationStatus
                ? $row->status->value
                : (string) $row->status,
        ])
        ->all();

    $tx = $contract->transactions()->latest('id')->first();
    $allocations = $tx
        ? $tx->allocations()->orderBy('id')->get()->map(fn ($a) => [
            'target' => $a->target instanceof AllocationTarget ? $a->target->value : (string) $a->target,
            'installment_id' => $a->amortization_installment_id,
            'amount' => (string) $a->amount,
            'principal' => (string) $a->principal,
            'interest' => (string) $a->interest,
        ])->all()
        : [];

    $activity = $tx
        ? Activity::query()
            ->where('subject_type', $contract::class)
            ->where('subject_id', $contract->id)
            ->where('properties->transaction_id', $tx->id)
            ->latest('id')
            ->first()
        : null;

    return [
        'count' => count($rows),
        'max_n' => $rows === [] ? 0 : max(array_column($rows, 'n')),
        'rows' => $rows,
        'tx_id' => $tx?->id,
        'tx_notes' => $tx?->notes,
        'tx_payment_option' => $tx?->payment_option,
        'allocations' => $allocations,
        'activity_description' => $activity?->description,
        'activity_properties' => $activity?->properties?->toArray(),
    ];
}

/** El id de cuota cambia entre contratos gemelos; el rastro comparable es target+montos. */
function allocationFingerprint(array $allocations): array
{
    return array_map(static fn (array $row) => [
        'target' => $row['target'],
        'amount' => $row['amount'],
        'principal' => $row['principal'],
        'interest' => $row['interest'],
    ], $allocations);
}

function runSurplusOption(string $option, bool $withSelection = true): array
{
    $contract = surplusFourContract($option.($withSelection ? 'S' : 'N'));
    $first = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();

    app(CascadeCollectionService::class)->process(
        $contract->id,
        '2500.00',
        $option,
        Carbon::parse('2026-09-09'),
        $withSelection ? [$first->id] : [],
    );

    return surplusSnapshot($contract->fresh());
}

it('abono_capital y reducir_plazo producen el mismo resultado numérico sobre el mismo escenario', function () {
    $capital = runSurplusOption('abono_capital');
    $plazo = runSurplusOption('reducir_plazo');

    expect($capital['count'])->toBe($plazo['count'])
        ->and($capital['max_n'])->toBe($plazo['max_n'])
        ->and($capital['rows'])->toEqual($plazo['rows'])
        ->and(allocationFingerprint($capital['allocations']))->toEqual(allocationFingerprint($plazo['allocations']))
        ->and($capital['max_n'])->toBe(4)
        ->and($capital['rows'][0]['extra'])->toBe('1500.00')
        ->and($capital['rows'][0]['value'])->toBe('1000.00')
        ->and($capital['rows'][0]['remaining'])->toBe('2500.00')
        ->and($capital['rows'][1]['value'])->toBe('1000.00')
        ->and($capital['rows'][3]['value'])->toBe('500.00')
        ->and(collect($capital['allocations'])->pluck('target')->all())->toBe(['installment', 'capital'])
        ->and($capital['allocations'][1]['amount'])->toBe('1500.00');

    $report = [
        'antes' => [
            'cuotas' => 5,
            'installment_value' => '1000.00',
            'max_n' => 5,
            'pago' => '2500.00',
            'deuda_cuota_1' => '1000.00',
            'excedente' => '1500.00',
        ],
        'abono_capital' => $capital,
        'reducir_plazo' => $plazo,
    ];
    file_put_contents('/tmp/surplus-capital-vs-plazo.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
});

it('reducir_cuota mantiene el número de cuotas y baja el installment_value futuro', function () {
    $result = runSurplusOption('reducir_cuota');

    expect($result['count'])->toBe(5)
        ->and($result['max_n'])->toBe(5)
        ->and($result['rows'][0]['extra'])->toBe('1500.00')
        ->and($result['rows'][0]['value'])->toBe('1000.00')
        ->and((float) $result['rows'][1]['value'])->toBeLessThan(1000)
        ->and($result['rows'][1]['value'])->toBe($result['rows'][2]['value'])
        ->and($result['rows'][1]['value'])->toBe($result['rows'][3]['value'])
        ->and($result['rows'][1]['value'])->toBe('375.00');

    file_put_contents('/tmp/surplus-reducir-cuota.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
});

it('adelantar_cuotas con selección aplica FIFO a cuotas futuras sin recalcular plazo ni cuota', function () {
    $result = runSurplusOption('adelantar_cuotas', withSelection: true);

    expect($result['count'])->toBe(5)
        ->and($result['max_n'])->toBe(5)
        ->and($result['rows'][0]['status'])->toBe('paid')
        ->and((float) $result['rows'][0]['extra'])->toBe(0.0)
        ->and($result['rows'][0]['value'])->toBe('1000.00')
        ->and($result['rows'][1]['status'])->toBe('paid')
        ->and($result['rows'][1]['value'])->toBe('1000.00')
        ->and((float) $result['rows'][1]['extra'])->toBe(0.0)
        ->and($result['rows'][2]['status'])->toBe('partial')
        ->and($result['rows'][2]['debt'])->toBe('500.00')
        ->and($result['rows'][2]['value'])->toBe('1000.00')
        ->and($result['rows'][3]['status'])->toBe('pending')
        ->and($result['rows'][4]['status'])->toBe('pending')
        ->and(collect($result['allocations'])->pluck('target')->unique()->values()->all())->toBe(['installment']);

    file_put_contents('/tmp/surplus-adelantar.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
});

it('los cuatro tokens son mutuamente excluyentes y el FormRequest no acepta combinaciones', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);
    $contract = surplusFourContract('HTTP');
    $first = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => 2500,
        'transaction_date' => '2026-09-09',
        'selected_installments' => [$first->id],
        'payment_option' => 'abono_capital,reducir_cuota',
    ])->assertStatus(422)->assertJsonValidationErrors(['payment_option']);

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => 2500,
        'transaction_date' => '2026-09-09',
        'selected_installments' => [$first->id],
        'payment_option' => ['abono_capital', 'reducir_cuota'],
    ])->assertStatus(422);
});

it('persiste payment_option en la transacción y lo proyecta a la bitácora sin tocar notes ni la frase', function () {
    $capital = runSurplusOption('abono_capital');
    $plazo = runSurplusOption('reducir_plazo');
    $cuota = runSurplusOption('reducir_cuota');
    $adelanto = runSurplusOption('adelantar_cuotas');

    expect(allocationFingerprint($capital['allocations']))->toEqual(allocationFingerprint($plazo['allocations']))
        ->and($capital['tx_payment_option'])->toBe('abono_capital')
        ->and($plazo['tx_payment_option'])->toBe('reducir_plazo')
        ->and($cuota['tx_payment_option'])->toBe('reducir_cuota')
        ->and($adelanto['tx_payment_option'])->toBe('adelantar_cuotas')
        ->and($capital['activity_properties']['payment_option'] ?? null)->toBe('abono_capital')
        ->and($plazo['activity_properties']['payment_option'] ?? null)->toBe('reducir_plazo')
        ->and($cuota['activity_properties']['payment_option'] ?? null)->toBe('reducir_cuota')
        ->and($adelanto['activity_properties']['payment_option'] ?? null)->toBe('adelantar_cuotas')
        ->and($capital['activity_description'])->toBe('Registró un pago de $2,500.00 mediante cash sobre el contrato')
        ->and($plazo['activity_description'])->toBe($capital['activity_description'])
        ->and($capital['tx_notes'])->toBeNull()
        ->and($plazo['tx_notes'])->toBeNull();
});

it('adelantar_cuotas sin selección no hace FIFO: el extra queda en la cuota corriente', function () {
    $result = runSurplusOption('adelantar_cuotas', withSelection: false);

    file_put_contents('/tmp/surplus-adelantar-sin-seleccion.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    expect($result['rows'][0]['status'])->toBe('paid')
        ->and($result['rows'][0]['extra'])->toBe('1500.00')
        ->and($result['rows'][1]['status'])->toBe('pending')
        ->and($result['rows'][1]['debt'])->toBe('1000.00')
        ->and($result['count'])->toBe(5);
});
