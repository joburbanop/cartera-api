<?php

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Financial\Transaction\RegularPayment\RegularPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function surplusCascadeContract(): Contract
{
    $project = Project::create([
        'name' => 'Proyecto Regular Surplus',
        'description' => 'Proyecto de prueba',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '1000000099',
        'name' => 'Cliente Regular Surplus',
        'phone' => '3000000099',
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'R-101',
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-REG-SURPLUS-001',
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

    foreach ([1, 2, 3] as $number) {
        $contract->amortizationInstallments()->create([
            'contract_id' => $contract->id,
            'installment_number' => $number,
            'due_date' => now()->addMonths($number - 2)->toDateString(),
            'installment_value' => 1000,
            'principal_value' => 800,
            'interest_value' => 200,
            'extra_payment' => 0,
            'remaining_balance' => 1000,
            'projected_balance' => 1000,
            'interest_paid' => 0,
            'principal_paid' => 0,
            'quota_debt' => 1000,
            'status' => AmortizationStatus::PENDING->value,
        ]);
    }

    return $contract;
}

it('cascades a regular overpayment onto later pending installments when no extraordinary option is selected', function () {
    $contract = surplusCascadeContract();
    $first = $contract->amortizationInstallments()->where('installment_number', 1)->first();

    app(RegularPaymentService::class)->registerRegularPayment(new CreateTransactionDTO(
        contractId: $contract->id,
        amount: '2500.00',
        transactionDate: Carbon::parse(now()->toDateString()),
        paymentMethod: PaymentMethod::CASH,
        transactionType: TransactionType::REGULAR_PAYMENT,
        installmentNumbers: [(int) $first->id],
    ));

    $first->refresh();
    $second = $contract->amortizationInstallments()->where('installment_number', 2)->first();
    $third = $contract->amortizationInstallments()->where('installment_number', 3)->first();

    expect($first->status)->toBe(AmortizationStatus::PAID)
        ->and($first->quota_debt)->toBe('0.00')
        ->and(number_format((float) $first->interest_paid, 2, '.', ''))->toBe('200.00')
        ->and(number_format((float) $first->principal_paid, 2, '.', ''))->toBe('800.00')
        ->and($first->remaining_balance)->toBe('1000.00')
        ->and($second->status)->toBe(AmortizationStatus::PAID)
        ->and($second->quota_debt)->toBe('0.00')
        ->and(number_format((float) $second->interest_paid, 2, '.', ''))->toBe('200.00')
        ->and(number_format((float) $second->principal_paid, 2, '.', ''))->toBe('800.00')
        ->and($second->remaining_balance)->toBe('1000.00')
        ->and($third->status)->toBe(AmortizationStatus::PARTIAL)
        ->and($third->quota_debt)->toBe('500.00')
        ->and(number_format((float) $third->interest_paid, 2, '.', ''))->toBe('200.00')
        ->and(number_format((float) $third->principal_paid, 2, '.', ''))->toBe('300.00')
        ->and($third->remaining_balance)->toBe('1000.00');
});

it('rejects a regular payment when the contract is already fully settled', function () {
    $contract = surplusCascadeContract();

    $contract->amortizationInstallments()->update([
        'status' => AmortizationStatus::PAID->value,
        'quota_debt' => '0.00',
        'interest_paid' => '200.00',
        'principal_paid' => '800.00',
        'payment_date' => now()->toDateString(),
    ]);

    $first = $contract->amortizationInstallments()->where('installment_number', 1)->first();

    try {
        app(RegularPaymentService::class)->registerRegularPayment(new CreateTransactionDTO(
            contractId: $contract->id,
            amount: '100.00',
            transactionDate: Carbon::parse(now()->toDateString()),
            paymentMethod: PaymentMethod::CASH,
            transactionType: TransactionType::REGULAR_PAYMENT,
            installmentNumbers: [(int) $first->id],
        ));
        expect(false)->toBeTrue('Se esperaba ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors()['amount'][0])->toBe('La obligación ya fue cumplida, no hay saldo pendiente para aplicar este pago.');
    }
});
