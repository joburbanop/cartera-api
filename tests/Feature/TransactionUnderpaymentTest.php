<?php

use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Models\AmortizationPlan;
use App\Models\Contract;
use App\Services\Financial\TransactionService;

it('marks underpayment as overdue when contract is active', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationPlan([
        'installment_number' => 1,
        'installment_value' => '100.00',
        'remaining_balance' => '100.00',
        'status' => AmortizationStatus::UNPAID->value,
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment(
        $plan,
        '70.00',
        $contract
    );

    expect($result['status'])->toBe(AmortizationStatus::OVERDUE)
        ->and($result['remaining_balance'])->toBe('30.00');
});

it('uses the installment value as debt when the plan residual is zero', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationPlan([
        'installment_number' => 1,
        'installment_value' => '100.00',
        'remaining_balance' => '0.00',
        'status' => AmortizationStatus::UNPAID->value,
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment(
        $plan,
        '70.00',
        $contract
    );

    expect($result['status'])->toBe(AmortizationStatus::OVERDUE)
        ->and($result['remaining_balance'])->toBe('30.00');
});

it('ignores the project residual and uses the installment value as the real debt', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationPlan([
        'installment_number' => 1,
        'installment_value' => '100.00',
        'remaining_balance' => '9000.00',
        'status' => AmortizationStatus::UNPAID->value,
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment(
        $plan,
        '70.00',
        $contract
    );

    expect($result['status'])->toBe(AmortizationStatus::OVERDUE)
        ->and($result['remaining_balance'])->toBe('30.00');
});

it('uses the total loan balance after the down payment for the first amortization row', function () {
    $contract = new Contract([
        'sale_price' => '355811368.00',
        'down_payment_pactada' => '74250000.00',
    ]);

    $principal = $contract->sale_price - $contract->down_payment_pactada;

    expect((string) number_format($principal, 2, '.', ''))->toBe('281561368.00');
});

it('keeps underpayment pending while contract is in preventa', function () {
    $contract = new Contract([
        'status' => ContractStatus::PREVENTA_INACTIVA->value,
    ]);

    $plan = new AmortizationPlan([
        'installment_number' => 0,
        'installment_value' => '200.00',
        'remaining_balance' => '200.00',
        'status' => AmortizationStatus::UNPAID->value,
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment(
        $plan,
        '120.00',
        $contract
    );

    expect($result['status'])->toBe(AmortizationStatus::PARTIAL)
        ->and($result['remaining_balance'])->toBe('80.00');
});
