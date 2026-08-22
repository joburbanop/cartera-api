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

it('applies a partial payment by interest first and keeps quota debt separate from the real amortization balance', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationPlan([
        'installment_number' => 1,
        'installment_value' => '4266081.34',
        'interest_value' => '1620000.00',
        'principal_value' => '2646081.34',
        'remaining_balance' => '87404400.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '4266081.34',
        'status' => AmortizationStatus::UNPAID->value,
        'due_date' => now()->subDay()->toDateString(),
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment(
        $plan,
        '2000000.00',
        $contract
    );

    expect($result['status'])->toBe(AmortizationStatus::OVERDUE)
        ->and($result['interest_paid'])->toBe('1620000.00')
        ->and($result['principal_paid'])->toBe('380000.00')
        ->and($result['quota_debt'])->toBe('2266081.34')
        ->and($result['remaining_balance'])->toBe('87024400.00');
});

it('accumulates multiple partial payments on the same installment without resetting the quota debt', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationPlan([
        'installment_number' => 1,
        'installment_value' => '4266081.34',
        'interest_value' => '1620000.00',
        'principal_value' => '2646081.34',
        'remaining_balance' => '87024400.00',
        'interest_paid' => '1620000.00',
        'principal_paid' => '380000.00',
        'quota_debt' => '2266081.34',
        'status' => AmortizationStatus::OVERDUE->value,
        'due_date' => now()->subDay()->toDateString(),
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment(
        $plan,
        '1620000.00',
        $contract
    );

    expect($result['status'])->toBe(AmortizationStatus::OVERDUE)
        ->and($result['interest_paid'])->toBe('1620000.00')
        ->and($result['principal_paid'])->toBe('2000000.00')
        ->and($result['quota_debt'])->toBe('646081.34')
        ->and($result['remaining_balance'])->toBe('85404400.00');
});

it('recalculates the real amortization balance from the previous residual when the plan residual is zero', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationPlan([
        'installment_number' => 1,
        'installment_value' => '100.00',
        'interest_value' => '0.00',
        'remaining_balance' => '0.00',
        'status' => AmortizationStatus::UNPAID->value,
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment(
        $plan,
        '70.00',
        $contract
    );

    expect($result['status'])->toBe(AmortizationStatus::OVERDUE)
        ->and($result['quota_debt'])->toBe('30.00')
        ->and($result['remaining_balance'])->toBe('0.00');
});

it('keeps the real amortization balance separate from the overdue quota debt even when the residual is larger', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationPlan([
        'installment_number' => 1,
        'installment_value' => '100.00',
        'interest_value' => '0.00',
        'remaining_balance' => '9000.00',
        'status' => AmortizationStatus::UNPAID->value,
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment(
        $plan,
        '70.00',
        $contract
    );

    expect($result['status'])->toBe(AmortizationStatus::OVERDUE)
        ->and($result['quota_debt'])->toBe('30.00')
        ->and($result['remaining_balance'])->toBe('8930.00');
});

it('closes the installment when the cumulative payments cover the full cuota value', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationPlan([
        'installment_number' => 1,
        'installment_value' => '4266081.34',
        'interest_value' => '1620000.00',
        'principal_value' => '2646081.34',
        'remaining_balance' => '87024400.00',
        'interest_paid' => '1620000.00',
        'principal_paid' => '380000.00',
        'quota_debt' => '2266081.34',
        'status' => AmortizationStatus::OVERDUE->value,
        'due_date' => now()->subDay()->toDateString(),
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment(
        $plan,
        '2266081.34',
        $contract
    );

    expect($result['status'])->toBe(AmortizationStatus::PAID)
        ->and($result['quota_debt'])->toBe('0.00')
        ->and($result['interest_paid'])->toBe('1620000.00')
        ->and($result['principal_paid'])->toBe('2646081.34')
        ->and($result['remaining_balance'])->toBe('84758318.66');
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
