<?php

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Collection\CascadeCollectionService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Contrato con cuota 4 vencida (due_date < hoy simulado) y cuota 5 futura (due_date > hoy).
 * Hoy congelado: 2026-06-15.
 */
function overdueDateScenariosContract(): Contract
{
    $suffix = (string) random_int(100000, 999999);

    $project = Project::create([
        'name' => 'Proyecto Mora Fechas '.$suffix,
        'description' => 'Proyecto de prueba',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '9'.$suffix,
        'name' => 'Cliente Mora Fechas '.$suffix,
        'phone' => '300'.$suffix,
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'M-'.$suffix,
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-MORA-'.$suffix,
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 3000,
        'down_payment_pactada' => 0,
        'term_months' => 5,
        'interest_rate' => 0,
        'start_date' => '2026-01-15',
        'initial_payment_date' => '2026-01-15',
        'first_installment_date' => '2026-02-15',
        'regular_payment_start_date' => '2026-02-15',
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 4,
        'due_date' => '2026-05-15',
        'installment_value' => '1000.00',
        'principal_value' => '800.00',
        'interest_value' => '200.00',
        'extra_payment' => '0.00',
        'remaining_balance' => '2000.00',
        'projected_balance' => '2000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000.00',
        'status' => AmortizationStatus::PENDING->value,
    ]);

    $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 5,
        'due_date' => '2026-07-15',
        'installment_value' => '1000.00',
        'principal_value' => '850.00',
        'interest_value' => '150.00',
        'extra_payment' => '0.00',
        'remaining_balance' => '1000.00',
        'projected_balance' => '1000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000.00',
        'status' => AmortizationStatus::PENDING->value,
    ]);

    return $contract;
}

function overdueScenarioRow(Contract $contract, int $number)
{
    return $contract->amortizationInstallments()->where('installment_number', $number)->first();
}

it('aplica un pago parcial sobre la cuota vencida: interés primero, quota_debt exacto, status partial y remaining_balance intacto', function () {
    $contract = overdueDateScenariosContract();
    $four = overdueScenarioRow($contract, 4);
    $remainingBefore = (string) $four->remaining_balance;

    app(CascadeCollectionService::class)->process(
        $contract->id,
        '500.00',
        null,
        Carbon::parse(now()->toDateString()),
        [(int) $four->id],
    );

    $four = $four->fresh();
    $five = overdueScenarioRow($contract, 5);

    expect($four->due_date->toDateString())->toBe('2026-05-15')
        ->and(now()->toDateString())->toBe('2026-06-15')
        ->and($four->due_date->lt(now()->startOfDay()))->toBeTrue()
        ->and(number_format((float) $four->interest_paid, 2, '.', ''))->toBe('200.00')
        ->and(number_format((float) $four->principal_paid, 2, '.', ''))->toBe('300.00')
        ->and($four->quota_debt)->toBe('500.00')
        ->and($four->status)->toBe(AmortizationStatus::PARTIAL)
        ->and($four->remaining_balance)->toBe($remainingBefore)
        ->and($four->projected_balance)->toBe('2000.00')
        ->and($five->quota_debt)->toBe('1000.00')
        ->and($five->status)->toBe(AmortizationStatus::PENDING)
        ->and(number_format((float) $five->interest_paid, 2, '.', ''))->toBe('0.00');
});

it('cascada el excedente de la cuota vencida a la siguiente pendiente, interés antes que capital', function () {
    $contract = overdueDateScenariosContract();
    $four = overdueScenarioRow($contract, 4);

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '1300.00',
        null,
        Carbon::parse(now()->toDateString()),
        [(int) $four->id],
    );

    $four = overdueScenarioRow($contract, 4);
    $five = overdueScenarioRow($contract, 5);

    expect($result['amount_applied'])->toBe('1300.00')
        ->and($four->status)->toBe(AmortizationStatus::PAID)
        ->and($four->quota_debt)->toBe('0.00')
        ->and(number_format((float) $four->interest_paid, 2, '.', ''))->toBe('200.00')
        ->and(number_format((float) $four->principal_paid, 2, '.', ''))->toBe('800.00')
        ->and($four->remaining_balance)->toBe('2000.00')
        ->and($five->status)->toBe(AmortizationStatus::PARTIAL)
        ->and($five->quota_debt)->toBe('700.00')
        ->and(number_format((float) $five->interest_paid, 2, '.', ''))->toBe('150.00')
        ->and(number_format((float) $five->principal_paid, 2, '.', ''))->toBe('150.00')
        ->and($five->remaining_balance)->toBe('1000.00');
});

it('reparte un pago explícito de varias cuotas de la más antigua a la más nueva hasta donde alcance', function () {
    $contract = overdueDateScenariosContract();
    $four = overdueScenarioRow($contract, 4);
    $five = overdueScenarioRow($contract, 5);

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '1700.00',
        null,
        Carbon::parse(now()->toDateString()),
        [(int) $four->id, (int) $five->id],
    );

    $four = $four->fresh();
    $five = $five->fresh();

    expect($result['amount_applied'])->toBe('1700.00')
        ->and($result['installments'])->toHaveCount(2)
        ->and($result['installments'][0]['installment_number'])->toBe(4)
        ->and($result['installments'][0]['amount_applied'])->toBe('1000.00')
        ->and($result['installments'][1]['installment_number'])->toBe(5)
        ->and($result['installments'][1]['amount_applied'])->toBe('700.00')
        ->and($four->status)->toBe(AmortizationStatus::PAID)
        ->and($four->quota_debt)->toBe('0.00')
        ->and(number_format((float) $four->interest_paid, 2, '.', ''))->toBe('200.00')
        ->and(number_format((float) $four->principal_paid, 2, '.', ''))->toBe('800.00')
        ->and($four->remaining_balance)->toBe('2000.00')
        ->and($five->status)->toBe(AmortizationStatus::PARTIAL)
        ->and($five->quota_debt)->toBe('300.00')
        ->and(number_format((float) $five->interest_paid, 2, '.', ''))->toBe('150.00')
        ->and(number_format((float) $five->principal_paid, 2, '.', ''))->toBe('550.00')
        ->and($five->remaining_balance)->toBe('1000.00');
});

it('rechaza un abono extraordinario si el monto no cubre la cuota vencida anterior', function () {
    $contract = overdueDateScenariosContract();
    $five = overdueScenarioRow($contract, 5);

    try {
        app(ExtraordinaryPaymentService::class)->registerExtraordinaryPayment(new CreateTransactionDTO(
            contractId: $contract->id,
            amount: '400.00',
            transactionDate: Carbon::parse(now()->toDateString()),
            paymentMethod: PaymentMethod::CASH,
            transactionType: TransactionType::EXTRAORDINARY_PAYMENT,
            installmentNumbers: [(int) $five->id],
            paymentOption: 'reducir_plazo',
        ));
        expect(false)->toBeTrue('Se esperaba ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors()['amount'][0])->toBe(
            'Debe saldar primero las cuotas atrasadas antes de aplicar un abono extraordinario.'
        );
    }

    $four = overdueScenarioRow($contract, 4);
    $five = $five->fresh();

    expect($four->quota_debt)->toBe('1000.00')
        ->and($four->status)->toBe(AmortizationStatus::PENDING)
        ->and($four->remaining_balance)->toBe('2000.00')
        ->and($five->extra_payment)->toBe('0.00')
        ->and($five->status)->toBe(AmortizationStatus::PENDING)
        ->and($five->remaining_balance)->toBe('1000.00');
});

it('salda primero la cuota vencida y envía solo el remanente a reducir_plazo', function () {
    $contract = overdueDateScenariosContract();
    $five = overdueScenarioRow($contract, 5);

    app(ExtraordinaryPaymentService::class)->registerExtraordinaryPayment(new CreateTransactionDTO(
        contractId: $contract->id,
        amount: '1500.00',
        transactionDate: Carbon::parse(now()->toDateString()),
        paymentMethod: PaymentMethod::CASH,
        transactionType: TransactionType::EXTRAORDINARY_PAYMENT,
        installmentNumbers: [(int) $five->id],
        paymentOption: 'reducir_plazo',
    ));

    $four = overdueScenarioRow($contract, 4);
    $five = overdueScenarioRow($contract, 5);

    expect($four->status)->toBe(AmortizationStatus::PAID)
        ->and($four->quota_debt)->toBe('0.00')
        ->and(number_format((float) $four->interest_paid, 2, '.', ''))->toBe('200.00')
        ->and(number_format((float) $four->principal_paid, 2, '.', ''))->toBe('800.00')
        ->and($four->remaining_balance)->toBe('2000.00')
        ->and($four->extra_payment)->toBe('0.00')
        ->and($five->status)->toBe(AmortizationStatus::PAID)
        ->and($five->extra_payment)->toBe('500.00')
        ->and($five->remaining_balance)->toBe('500.00')
        ->and($five->projected_balance)->toBe('500.00');
});

it('expone estado_cartera vencida con la cuota 4 impaga y al_dia al cubrirla', function () {
    $contract = overdueDateScenariosContract();
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $this->getJson("/api/customers/{$contract->customer_id}")
        ->assertOk()
        ->assertJsonPath('data.estadoCartera', 'vencida');

    $four = overdueScenarioRow($contract, 4);

    app(CascadeCollectionService::class)->process(
        $contract->id,
        '1000.00',
        null,
        Carbon::parse(now()->toDateString()),
        [(int) $four->id],
    );

    expect(overdueScenarioRow($contract, 4)->status)->toBe(AmortizationStatus::PAID)
        ->and(overdueScenarioRow($contract, 5)->status)->toBe(AmortizationStatus::PENDING);

    $this->getJson("/api/customers/{$contract->customer_id}")
        ->assertOk()
        ->assertJsonPath('data.estadoCartera', 'al_dia');
});

it('aplica el dinero primero a la vencida aunque solo se seleccione una cuota futura', function () {
    $contract = overdueDateScenariosContract();
    $four = overdueScenarioRow($contract, 4);
    $five = overdueScenarioRow($contract, 5);

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '1700.00',
        null,
        Carbon::parse(now()->toDateString()),
        [(int) $five->id],
    );

    $four = $four->fresh();
    $five = $five->fresh();

    expect($result['amount_applied'])->toBe('1700.00')
        ->and($result['installments'])->toHaveCount(2)
        ->and($result['installments'][0]['installment_number'])->toBe(4)
        ->and($result['installments'][0]['amount_applied'])->toBe('1000.00')
        ->and($result['installments'][1]['installment_number'])->toBe(5)
        ->and($result['installments'][1]['amount_applied'])->toBe('700.00')
        ->and($four->status)->toBe(AmortizationStatus::PAID)
        ->and($four->quota_debt)->toBe('0.00')
        ->and(number_format((float) $four->interest_paid, 2, '.', ''))->toBe('200.00')
        ->and(number_format((float) $four->principal_paid, 2, '.', ''))->toBe('800.00')
        ->and($four->remaining_balance)->toBe('2000.00')
        ->and($five->status)->toBe(AmortizationStatus::PARTIAL)
        ->and($five->quota_debt)->toBe('300.00')
        ->and(number_format((float) $five->interest_paid, 2, '.', ''))->toBe('150.00')
        ->and(number_format((float) $five->principal_paid, 2, '.', ''))->toBe('550.00')
        ->and($five->remaining_balance)->toBe('1000.00');
});

it('deja la cuota seleccionada intacta si el monto no cubre la vencida, sin rechazar el pago', function () {
    $contract = overdueDateScenariosContract();
    $four = overdueScenarioRow($contract, 4);
    $five = overdueScenarioRow($contract, 5);

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '400.00',
        null,
        Carbon::parse(now()->toDateString()),
        [(int) $five->id],
    );

    $four = $four->fresh();
    $five = $five->fresh();

    expect($result['amount_applied'])->toBe('400.00')
        ->and($four->status)->toBe(AmortizationStatus::PARTIAL)
        ->and($four->quota_debt)->toBe('600.00')
        ->and(number_format((float) $four->interest_paid, 2, '.', ''))->toBe('200.00')
        ->and(number_format((float) $four->principal_paid, 2, '.', ''))->toBe('200.00')
        ->and($five->status)->toBe(AmortizationStatus::PENDING)
        ->and($five->quota_debt)->toBe('1000.00')
        ->and(number_format((float) $five->interest_paid, 2, '.', ''))->toBe('0.00')
        ->and(number_format((float) $five->principal_paid, 2, '.', ''))->toBe('0.00')
        ->and($five->extra_payment)->toBe('0.00');
});
