<?php

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Collection\CascadeCollectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2027-01-15 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function createNoSelectionContract(string $suffix, bool $withOverdues = true, bool $withFuture = true): Contract
{
    $project = Project::create([
        'name' => 'Proyecto No Seleccion '.$suffix,
        'description' => 'Proyecto de prueba',
        'location' => 'Bogota',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '7'.$suffix,
        'name' => 'Cliente No Seleccion '.$suffix,
        'phone' => '300'.$suffix,
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'NS-'.$suffix,
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);

    return Contract::create([
        'contract_number' => 'CT-NS-'.$suffix,
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 4000,
        'down_payment_pactada' => 0,
        'term_months' => 4,
        'interest_rate' => 0,
        'start_date' => '2026-10-15',
        'initial_payment_date' => '2026-10-15',
        'first_installment_date' => '2026-11-15',
        'regular_payment_start_date' => '2026-11-15',
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);
}

function createNoSelectionInstallment(
    Contract $contract,
    int $number,
    string $dueDate,
    string $remainingBalance,
): AmortizationInstallment {
    return $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => $number,
        'due_date' => $dueDate,
        'installment_value' => '1000.00',
        'principal_value' => '1000.00',
        'interest_value' => '0.00',
        'extra_payment' => '0.00',
        'remaining_balance' => $remainingBalance,
        'projected_balance' => $remainingBalance,
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000.00',
        'status' => AmortizationStatus::PENDING->value,
    ]);
}

it('sin cuotas vencidas, aplicar reducir_plazo sin seleccion explicita usa la primera pendiente y recalcula el futuro', function () {
    $suffix = (string) random_int(100000, 999999);
    $contract = createNoSelectionContract($suffix, withOverdues: false, withFuture: true);

    $first = createNoSelectionInstallment($contract, 1, '2027-02-15', '2000.00');
    createNoSelectionInstallment($contract, 2, '2027-03-15', '1000.00');
    createNoSelectionInstallment($contract, 3, '2027-04-15', '0.00');

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '2500.00',
        'reducir_plazo',
        Carbon::parse(now()->toDateString()),
        [],
    );

    $first->refresh();
    $future = $contract->amortizationInstallments()
        ->where('installment_number', '>', 1)
        ->orderBy('installment_number')
        ->get();

    expect($result['amount_applied'])->toBe('2500.00')
        ->and($result['installments'])->toHaveCount(1)
        ->and($result['installments'][0]['installment_number'])->toBe(1)
        ->and($result['installments'][0]['amount_applied'])->toBe('2500.00')
        ->and($first->status)->toBe(AmortizationStatus::PAID)
        ->and($first->quota_debt)->toBe('0.00')
        ->and($first->extra_payment)->toBe('1500.00')
        ->and($future)->toHaveCount(1)
        ->and((string) $future[0]->installment_number)->toBe('2')
        ->and($future[0]->installment_value)->toBe('500.00')
        ->and($future[0]->quota_debt)->toBe('500.00');
});

it('con dos vencidas, reducir_cuota sin seleccion paga vencidas y envia sobrante a la siguiente pendiente', function () {
    $suffix = (string) random_int(100000, 999999);
    $contract = createNoSelectionContract($suffix);

    $first = createNoSelectionInstallment($contract, 1, '2026-12-15', '3000.00');
    $second = createNoSelectionInstallment($contract, 2, '2027-01-10', '2000.00');
    $third = createNoSelectionInstallment($contract, 3, '2027-02-15', '1000.00');
    $fourth = createNoSelectionInstallment($contract, 4, '2027-03-15', '0.00');

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '3500.00',
        'reducir_cuota',
        Carbon::parse(now()->toDateString()),
        [],
    );

    $first->refresh();
    $second->refresh();
    $third->refresh();
    $fourth->refresh();

    expect($result['amount_applied'])->toBe('3500.00')
        ->and($result['installments'])->toHaveCount(3)
        ->and($result['installments'][0]['installment_number'])->toBe(1)
        ->and($result['installments'][0]['amount_applied'])->toBe('1000.00')
        ->and($result['installments'][1]['installment_number'])->toBe(2)
        ->and($result['installments'][1]['amount_applied'])->toBe('1000.00')
        ->and($result['installments'][2]['installment_number'])->toBe(3)
        ->and($result['installments'][2]['amount_applied'])->toBe('1500.00')
        ->and($first->status)->toBe(AmortizationStatus::PAID)
        ->and($first->quota_debt)->toBe('0.00')
        ->and($second->status)->toBe(AmortizationStatus::PAID)
        ->and($second->quota_debt)->toBe('0.00')
        ->and($third->status)->toBe(AmortizationStatus::PAID)
        ->and($third->extra_payment)->toBe('500.00')
        ->and($fourth->installment_value)->toBe('500.00')
        ->and($fourth->quota_debt)->toBe('500.00');
});

it('con dos vencidas, adelantar_cuotas sin seleccion no regenera el plan futuro', function () {
    $suffix = (string) random_int(100000, 999999);
    $contract = createNoSelectionContract($suffix);

    $first = createNoSelectionInstallment($contract, 1, '2026-12-15', '3000.00');
    $second = createNoSelectionInstallment($contract, 2, '2027-01-10', '2000.00');
    $third = createNoSelectionInstallment($contract, 3, '2027-02-15', '1000.00');
    $fourth = createNoSelectionInstallment($contract, 4, '2027-03-15', '0.00');

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '3500.00',
        'adelantar_cuotas',
        Carbon::parse(now()->toDateString()),
        [],
    );

    $first->refresh();
    $second->refresh();
    $third->refresh();
    $fourth->refresh();

    expect($result['amount_applied'])->toBe('3500.00')
        ->and($result['installments'])->toHaveCount(3)
        ->and($result['installments'][2]['installment_number'])->toBe(3)
        ->and($result['installments'][2]['amount_applied'])->toBe('1500.00')
        ->and($first->status)->toBe(AmortizationStatus::PAID)
        ->and($second->status)->toBe(AmortizationStatus::PAID)
        ->and($third->status)->toBe(AmortizationStatus::PAID)
        ->and($third->extra_payment)->toBe('500.00')
        ->and($fourth->status)->toBe(AmortizationStatus::PENDING)
        ->and($fourth->installment_value)->toBe('1000.00')
        ->and($fourth->quota_debt)->toBe('1000.00');
});

it('si el pago con payment_option solo cubre vencidas no aplica estrategia y no falla', function () {
    $suffix = (string) random_int(100000, 999999);
    $contract = createNoSelectionContract($suffix);

    $first = createNoSelectionInstallment($contract, 1, '2026-12-15', '3000.00');
    $second = createNoSelectionInstallment($contract, 2, '2027-01-10', '2000.00');
    $third = createNoSelectionInstallment($contract, 3, '2027-02-15', '1000.00');

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '2000.00',
        'reducir_cuota',
        Carbon::parse(now()->toDateString()),
        [],
    );

    $first->refresh();
    $second->refresh();
    $third->refresh();

    expect($result['amount_applied'])->toBe('2000.00')
        ->and($result['installments'])->toHaveCount(2)
        ->and($result['installments'][0]['amount_applied'])->toBe('1000.00')
        ->and($result['installments'][1]['amount_applied'])->toBe('1000.00')
        ->and($first->status)->toBe(AmortizationStatus::PAID)
        ->and($second->status)->toBe(AmortizationStatus::PAID)
        ->and($third->status)->toBe(AmortizationStatus::PENDING)
        ->and($third->extra_payment)->toBe('0.00')
        ->and($third->quota_debt)->toBe('1000.00');
});

it('si no queda cuota pendiente para aplicar el sobrante mantiene el rechazo de obligacion cumplida', function () {
    $suffix = (string) random_int(100000, 999999);
    $contract = createNoSelectionContract($suffix, withFuture: false);

    $first = createNoSelectionInstallment($contract, 1, '2026-12-15', '1000.00');
    $second = createNoSelectionInstallment($contract, 2, '2027-01-10', '0.00');

    try {
        app(CascadeCollectionService::class)->process(
            $contract->id,
            '2500.00',
            'reducir_plazo',
            Carbon::parse(now()->toDateString()),
            [],
        );
        expect(false)->toBeTrue('Se esperaba ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors()['amount'][0])->toBe('La obligación ya fue cumplida, no hay saldo pendiente para aplicar este pago.');
    }

    $first->refresh();
    $second->refresh();

    expect($first->status)->toBe(AmortizationStatus::PENDING)
        ->and($first->quota_debt)->toBe('1000.00')
        ->and($second->status)->toBe(AmortizationStatus::PENDING)
        ->and($second->quota_debt)->toBe('1000.00');
});
