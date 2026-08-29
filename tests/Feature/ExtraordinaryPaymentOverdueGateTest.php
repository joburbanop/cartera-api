<?php

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use App\Services\Financial\Transaction\RegularPayment\RegularPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function overdueGateContract(): Contract
{
    $project = Project::create([
        'name' => 'Proyecto Atrasadas Extra',
        'description' => 'Proyecto de prueba',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '1000000200',
        'name' => 'Cliente Atrasadas Extra',
        'phone' => '3000000200',
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'E-201',
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-OVERDUE-GATE-001',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 3000,
        'down_payment_pactada' => 0,
        'term_months' => 3,
        'interest_rate' => 0,
        'start_date' => now()->subMonths(3)->toDateString(),
        'initial_payment_date' => now()->subMonths(3)->toDateString(),
        'first_installment_date' => now()->subMonths(2)->toDateString(),
        'regular_payment_start_date' => now()->subMonths(2)->toDateString(),
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    $rows = [
        1 => ['due' => now()->subMonths(2), 'status' => AmortizationStatus::OVERDUE, 'remaining' => 3000],
        2 => ['due' => now()->subMonth(), 'status' => AmortizationStatus::OVERDUE, 'remaining' => 2000],
        3 => ['due' => now(), 'status' => AmortizationStatus::PENDING, 'remaining' => 1000],
    ];

    foreach ($rows as $number => $row) {
        $contract->amortizationInstallments()->create([
            'contract_id' => $contract->id,
            'installment_number' => $number,
            'due_date' => $row['due']->toDateString(),
            'installment_value' => 1000,
            'principal_value' => 1000,
            'interest_value' => 0,
            'extra_payment' => 0,
            'remaining_balance' => $row['remaining'],
            'projected_balance' => $row['remaining'],
            'interest_paid' => 0,
            'principal_paid' => 0,
            'quota_debt' => 1000,
            'status' => $row['status']->value,
        ]);
    }

    return $contract;
}

it('rejects an extraordinary payment when the amount does not cover prior overdue installments', function () {
    $contract = overdueGateContract();
    $target = $contract->amortizationInstallments()->where('installment_number', 3)->first();

    try {
        app(ExtraordinaryPaymentService::class)->registerExtraordinaryPayment(new CreateTransactionDTO(
            contractId: $contract->id,
            amount: '1500.00',
            transactionDate: Carbon::parse(now()->toDateString()),
            paymentMethod: PaymentMethod::CASH,
            transactionType: TransactionType::EXTRAORDINARY_PAYMENT,
            installmentNumbers: [(int) $target->id],
            paymentOption: 'reducir_plazo',
        ));
        expect(false)->toBeTrue('Se esperaba ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors()['amount'][0])->toBe(
            'Debe saldar primero las cuotas atrasadas antes de aplicar un abono extraordinario.'
        );
    }

    expect($contract->amortizationInstallments()->where('installment_number', 1)->first()->status)
        ->toBe(AmortizationStatus::OVERDUE)
        ->and($contract->amortizationInstallments()->where('installment_number', 1)->first()->quota_debt)->toBe('1000.00')
        ->and($contract->amortizationInstallments()->where('installment_number', 2)->first()->status)
        ->toBe(AmortizationStatus::OVERDUE)
        ->and($contract->amortizationInstallments()->where('installment_number', 3)->first()->extra_payment)->toBe('0.00');
});

it('settles prior overdue installments first and sends only the remainder to the extraordinary strategy', function () {
    $contract = overdueGateContract();
    $target = $contract->amortizationInstallments()->where('installment_number', 3)->first();

    app(ExtraordinaryPaymentService::class)->registerExtraordinaryPayment(new CreateTransactionDTO(
        contractId: $contract->id,
        amount: '2500.00',
        transactionDate: Carbon::parse(now()->toDateString()),
        paymentMethod: PaymentMethod::CASH,
        transactionType: TransactionType::EXTRAORDINARY_PAYMENT,
        installmentNumbers: [(int) $target->id],
        paymentOption: 'reducir_plazo',
    ));

    $first = $contract->amortizationInstallments()->where('installment_number', 1)->first();
    $second = $contract->amortizationInstallments()->where('installment_number', 2)->first();
    $third = $contract->amortizationInstallments()->where('installment_number', 3)->first();

    expect($first->status)->toBe(AmortizationStatus::PAID)
        ->and($first->quota_debt)->toBe('0.00')
        ->and(number_format((float) $first->principal_paid, 2, '.', ''))->toBe('1000.00')
        ->and($first->remaining_balance)->toBe('3000.00')
        ->and($second->status)->toBe(AmortizationStatus::PAID)
        ->and($second->quota_debt)->toBe('0.00')
        ->and(number_format((float) $second->principal_paid, 2, '.', ''))->toBe('1000.00')
        ->and($third->status)->toBe(AmortizationStatus::PAID)
        ->and($third->extra_payment)->toBe('500.00')
        ->and($third->remaining_balance)->toBe('500.00')
        ->and($third->projected_balance)->toBe('500.00');
});

it('rejects a regular payment with extraordinary option when prior overdue installments are not covered', function () {
    $contract = overdueGateContract();
    $target = $contract->amortizationInstallments()->where('installment_number', 3)->first();

    try {
        app(RegularPaymentService::class)->registerRegularPayment(new CreateTransactionDTO(
            contractId: $contract->id,
            amount: '1500.00',
            transactionDate: Carbon::parse(now()->toDateString()),
            paymentMethod: PaymentMethod::CASH,
            transactionType: TransactionType::REGULAR_PAYMENT,
            installmentNumbers: [(int) $target->id],
            paymentOption: 'reducir_plazo',
        ));
        expect(false)->toBeTrue('Se esperaba ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors()['amount'][0])->toBe(
            'Debe saldar primero las cuotas atrasadas antes de aplicar un abono extraordinario.'
        );
    }

    expect($contract->amortizationInstallments()->where('installment_number', 1)->first()->quota_debt)
        ->toBe('1000.00')
        ->and($contract->amortizationInstallments()->where('installment_number', 3)->first()->status)
        ->toBe(AmortizationStatus::PENDING);
});
