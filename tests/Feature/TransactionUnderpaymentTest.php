<?php

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Services\Financial\Transaction\RegularPayment\RegularPaymentService;
use App\Services\Financial\Transaction\TransactionService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function createTransactionsTableForSqlite(): void
{
    Schema::create('transactions', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
        $table->string('transaction_type');
        $table->decimal('amount', 15, 2);
        $table->date('transaction_date');
        $table->string('payment_method')->default('cash');
        $table->timestamps();
    });
}

it('absorbs an underpayment residual under the tolerance while freezing the real amortization balance when contract is active', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationInstallment([
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

    expect($result['status'])->toBe(AmortizationStatus::PAID)
        ->and($result['remaining_balance'])->toBe('100.00')
        ->and($result['quota_debt'])->toBe('0.00')
        ->and($result['interest_paid'])->toBe('0.00')
        ->and($result['principal_paid'])->toBe('100.00');
});

it('applies a partial payment by interest first and keeps quota debt separate from the real amortization balance', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationInstallment([
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
        ->and($result['remaining_balance'])->toBe('87404400.00');
});

it('accumulates multiple partial payments on the same installment without resetting the quota debt', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationInstallment([
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
        ->and($result['remaining_balance'])->toBe('87024400.00');
});

it('recalculates the real amortization balance from the previous residual when the plan residual is zero', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationInstallment([
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

    expect($result['status'])->toBe(AmortizationStatus::PAID)
        ->and($result['quota_debt'])->toBe('0.00')
        ->and($result['remaining_balance'])->toBe('0.00')
        ->and($result['interest_paid'])->toBe('0.00')
        ->and($result['principal_paid'])->toBe('100.00');
});

it('keeps the real amortization balance separate from the outstanding quota debt even when the residual is larger', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationInstallment([
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

    expect($result['status'])->toBe(AmortizationStatus::PAID)
        ->and($result['quota_debt'])->toBe('0.00')
        ->and($result['remaining_balance'])->toBe('9000.00')
        ->and($result['interest_paid'])->toBe('0.00')
        ->and($result['principal_paid'])->toBe('100.00');
});

it('keeps the initial payment balance unchanged while reducing only the initial quota debt', function () {
    $contract = new Contract([
        'status' => ContractStatus::PREVENTA_INACTIVA->value,
    ]);

    $plan = new AmortizationInstallment([
        'installment_number' => 0,
        'installment_value' => '10519600.00',
        'remaining_balance' => '94676400.00',
        'quota_debt' => '10519600.00',
        'status' => AmortizationStatus::UNPAID->value,
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment(
        $plan,
        '5000000.00',
        $contract
    );

    expect($result['status'])->toBe(AmortizationStatus::PARTIAL)
        ->and($result['quota_debt'])->toBe('5519600.00')
        ->and($result['remaining_balance'])->toBe('94676400.00');
});

it('absorbs a residual below 500 as paid under the business tolerance rule', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationInstallment([
        'installment_number' => 1,
        'installment_value' => '1000.00',
        'interest_value' => '400.00',
        'principal_value' => '600.00',
        'remaining_balance' => '1000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000.00',
        'status' => AmortizationStatus::UNPAID->value,
        'due_date' => now()->subDay()->toDateString(),
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment($plan, '950.00', $contract);

    expect($result['status'])->toBe(AmortizationStatus::PAID)
        ->and($result['quota_debt'])->toBe('0.00');
});

it('keeps a future-due partial payment as partial instead of overdue', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationInstallment([
        'installment_number' => 1,
        'installment_value' => '2106024.23',
        'interest_value' => '946764.00',
        'principal_value' => '1159260.23',
        'remaining_balance' => '94676400.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '2106024.23',
        'status' => AmortizationStatus::UNPAID->value,
        'due_date' => now()->addDay()->toDateString(),
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment($plan, '1500000.00', $contract);

    expect($result['status'])->toBe(AmortizationStatus::PARTIAL)
        ->and($result['quota_debt'])->toBe('606024.23');
});

it('absorbs tiny rounding surpluses without creating a fake extra payment', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationInstallment([
        'installment_number' => 1,
        'installment_value' => '100.00',
        'interest_value' => '30.00',
        'principal_value' => '70.00',
        'remaining_balance' => '1000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '100.00',
        'status' => AmortizationStatus::UNPAID->value,
        'due_date' => now()->subDay()->toDateString(),
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment($plan, '100.50', $contract);

    expect($result['status'])->toBe(AmortizationStatus::PAID)
        ->and($result['quota_debt'])->toBe('0.00')
        ->and($result['excedente'])->toBe('0.00');
});

it('applies the LOTE 6 payment rules for partial, exact, and surplus payments', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationInstallment([
        'installment_number' => 1,
        'installment_value' => '2106024.23',
        'interest_value' => '946764.00',
        'principal_value' => '1159260.23',
        'remaining_balance' => '94676400.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '2106024.23',
        'status' => AmortizationStatus::UNPAID->value,
        'due_date' => '2025-11-05',
    ]);

    $partial = app(TransactionService::class)->calculatePaymentImpactForInstallment($plan, '1500000.00', $contract);

    expect($partial['status'])->toBe(AmortizationStatus::OVERDUE)
        ->and($partial['remaining_balance'])->toBe('94676400.00')
        ->and($partial['quota_debt'])->toBe('606024.23')
        ->and($partial['interest_paid'])->toBe('946764.00')
        ->and($partial['principal_paid'])->toBe('553236.00');

    $exact = app(TransactionService::class)->calculatePaymentImpactForInstallment($plan, '2106024.23', $contract);

    expect($exact['status'])->toBe(AmortizationStatus::PAID)
        ->and($exact['remaining_balance'])->toBe('94676400.00')
        ->and($exact['quota_debt'])->toBe('0.00')
        ->and($exact['interest_paid'])->toBe('946764.00')
        ->and($exact['principal_paid'])->toBe('1159260.23');

    $surplus = app(TransactionService::class)->calculatePaymentImpactForInstallment($plan, '7106024.23', $contract);

    expect($surplus['status'])->toBe(AmortizationStatus::PAID)
        ->and($surplus['remaining_balance'])->toBe('89676400.00')
        ->and($surplus['quota_debt'])->toBe('0.00')
        ->and($surplus['excedente'])->toBe('5000000.00');
});

it('reduces the current row balance by the surplus when the payment exceeds the scheduled installment', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationInstallment([
        'installment_number' => 1,
        'installment_value' => '2106024.23',
        'interest_value' => '946764.00',
        'principal_value' => '1159260.23',
        'remaining_balance' => '77238533.17',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '2106024.23',
        'status' => AmortizationStatus::UNPAID->value,
        'due_date' => '2025-11-05',
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment($plan, '7106024.23', $contract);

    expect($result['status'])->toBe(AmortizationStatus::PAID)
        ->and($result['remaining_balance'])->toBe('72238533.17')
        ->and($result['quota_debt'])->toBe('0.00')
        ->and($result['excedente'])->toBe('5000000.00');
});

it('caps the surplus to the remaining balance so it never goes below zero', function () {
    Schema::create('contracts', function (Blueprint $table) {
        $table->id();
        $table->string('contract_number')->unique();
        $table->string('status')->default('activo');
        $table->decimal('sale_price', 15, 2)->default(0);
        $table->decimal('down_payment_pactada', 15, 2)->default(0);
        $table->decimal('interest_rate', 5, 2)->default(1.00);
        $table->timestamps();
    });

    Schema::create('amortization_installments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
        $table->integer('installment_number');
        $table->date('due_date');
        $table->string('status')->default('sin_pagar');
        $table->decimal('installment_value', 15, 2)->default(0);
        $table->decimal('extra_payment', 15, 2)->default(0);
        $table->decimal('interest_value', 15, 2)->default(0);
        $table->decimal('principal_value', 15, 2)->default(0);
        $table->decimal('interest_paid', 15, 2)->default(0);
        $table->decimal('principal_paid', 15, 2)->default(0);
        $table->decimal('quota_debt', 15, 2)->default(0);
        $table->decimal('remaining_balance', 15, 2)->default(0);
        $table->decimal('projected_balance', 15, 2)->default(0);
        $table->dateTime('payment_date')->nullable();
        $table->timestamps();
    });

    createTransactionsTableForSqlite();

    $contract = Contract::query()->create([
        'contract_number' => 'CT-SURPLUS-LIMIT-001',
        'status' => ContractStatus::ACTIVO->value,
        'sale_price' => '87404400.00',
        'down_payment_pactada' => '0.00',
        'interest_rate' => '1.00',
    ]);

    $previous = AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 4,
        'due_date' => '2025-10-05',
        'installment_value' => '2301693.09',
        'principal_value' => '1773918.29',
        'interest_value' => '527774.80',
        'extra_payment' => '0.00',
        'remaining_balance' => '41003562.20',
        'projected_balance' => '41003562.20',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '2301693.09',
        'status' => AmortizationStatus::UNPAID->value,
    ]);

    $current = AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 5,
        'due_date' => '2025-11-05',
        'installment_value' => '2301693.09',
        'principal_value' => '1891657.47',
        'interest_value' => '410035.62',
        'extra_payment' => '0.00',
        'remaining_balance' => '41003562.20',
        'projected_balance' => '41003562.20',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '2301693.09',
        'status' => AmortizationStatus::UNPAID->value,
    ]);

    $updated = app(\App\Services\Financial\Transaction\ExtraordinaryPayment\Options\TermReductionService::class)
        ->apply($contract, $current, '44083317.59');

    expect($updated->remaining_balance)->toBe('0.00')
        ->and($updated->projected_balance)->toBe('0.00')
        ->and($updated->extra_payment)->toBe('41003562.20');
});

it('closes the installment when the cumulative payments cover the full cuota value', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationInstallment([
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
        ->and($result['remaining_balance'])->toBe('87024400.00');
});

it('uses the total loan balance after the down payment for the first amortization row', function () {
    $contract = new Contract([
        'sale_price' => '355811368.00',
        'down_payment_pactada' => '74250000.00',
    ]);

    $principal = $contract->sale_price - $contract->down_payment_pactada;

    expect((string) number_format($principal, 2, '.', ''))->toBe('281561368.00');
});

it('absorbs an underpayment residual under the tolerance in preventa without reducing the credit balance', function () {
    $contract = new Contract([
        'status' => ContractStatus::PREVENTA_INACTIVA->value,
    ]);

    $plan = new AmortizationInstallment([
        'installment_number' => 0,
        'installment_value' => '200.00',
        'remaining_balance' => '200.00',
        'quota_debt' => '200.00',
        'status' => AmortizationStatus::UNPAID->value,
    ]);

    $result = app(TransactionService::class)->calculatePaymentImpactForInstallment(
        $plan,
        '120.00',
        $contract
    );

    expect($result['status'])->toBe(AmortizationStatus::PAID)
        ->and($result['quota_debt'])->toBe('0.00')
        ->and($result['remaining_balance'])->toBe('200.00')
        ->and($result['interest_paid'])->toBe('0.00')
        ->and($result['principal_paid'])->toBe('200.00');
});

it('uses the full principal amortized when applying a surplus payment to the installment balance', function () {
    Schema::create('contracts', function (Blueprint $table) {
        $table->id();
        $table->string('contract_number')->unique();
        $table->string('status')->default('activo');
        $table->decimal('interest_rate', 5, 2)->default(1.00);
        $table->timestamps();
    });

    Schema::create('amortization_installments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
        $table->integer('installment_number');
        $table->date('due_date');
        $table->string('status')->default('sin_pagar');
        $table->decimal('installment_value', 15, 2)->default(0);
        $table->decimal('extra_payment', 15, 2)->default(0);
        $table->decimal('interest_value', 15, 2)->default(0);
        $table->decimal('principal_value', 15, 2)->default(0);
        $table->decimal('interest_paid', 15, 2)->default(0);
        $table->decimal('principal_paid', 15, 2)->default(0);
        $table->decimal('quota_debt', 15, 2)->default(0);
        $table->decimal('remaining_balance', 15, 2)->default(0);
        $table->decimal('projected_balance', 15, 2)->default(0);
        $table->dateTime('payment_date')->nullable();
        $table->timestamps();
    });

    createTransactionsTableForSqlite();

    $contract = Contract::query()->create([
        'contract_number' => 'CT-SURPLUS-001',
        'status' => ContractStatus::ACTIVO->value,
        'interest_rate' => '1.00',
    ]);

    $installment = AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 2,
        'due_date' => '2025-12-05',
        'installment_value' => '2301693.09',
        'principal_value' => '1541925.58',
        'interest_value' => '759767.51',
        'extra_payment' => '0.00',
        'remaining_balance' => '75976750.91',
        'projected_balance' => '75976750.91',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '2301693.09',
        'status' => AmortizationStatus::UNPAID->value,
    ]);

    $updated = app(\App\Services\Financial\Transaction\ExtraordinaryPayment\Options\PaymentReductionService::class)
        ->apply($contract, $installment, '10000000.00');

    expect($updated->principal_value)->toBe('11541925.58')
        ->and($updated->extra_payment)->toBe('10000000.00')
        ->and($updated->remaining_balance)->toBe('64434825.33')
        ->and($updated->projected_balance)->toBe('64434825.33');
});

it('uses the previous installment balance in the term reduction path instead of the contract fallback', function () {
    Schema::create('contracts', function (Blueprint $table) {
        $table->id();
        $table->string('contract_number')->unique();
        $table->string('status')->default('activo');
        $table->decimal('sale_price', 15, 2)->default(0);
        $table->decimal('down_payment_pactada', 15, 2)->default(0);
        $table->decimal('interest_rate', 5, 2)->default(1.00);
        $table->timestamps();
    });

    Schema::create('amortization_installments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
        $table->integer('installment_number');
        $table->date('due_date');
        $table->string('status')->default('sin_pagar');
        $table->decimal('installment_value', 15, 2)->default(0);
        $table->decimal('extra_payment', 15, 2)->default(0);
        $table->decimal('interest_value', 15, 2)->default(0);
        $table->decimal('principal_value', 15, 2)->default(0);
        $table->decimal('interest_paid', 15, 2)->default(0);
        $table->decimal('principal_paid', 15, 2)->default(0);
        $table->decimal('quota_debt', 15, 2)->default(0);
        $table->decimal('remaining_balance', 15, 2)->default(0);
        $table->decimal('projected_balance', 15, 2)->default(0);
        $table->dateTime('payment_date')->nullable();
        $table->timestamps();
    });

    createTransactionsTableForSqlite();

    $contract = Contract::query()->create([
        'contract_number' => 'CT-TERM-SEED-001',
        'status' => ContractStatus::ACTIVO->value,
        'sale_price' => '87404400.00',
        'down_payment_pactada' => '0.00',
        'interest_rate' => '1.00',
    ]);

    $first = AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 1,
        'due_date' => '2025-11-05',
        'installment_value' => '2301693.09',
        'principal_value' => '1427649.09',
        'interest_value' => '874044.00',
        'extra_payment' => '10000000.00',
        'remaining_balance' => '75976750.91',
        'projected_balance' => '75976750.91',
        'interest_paid' => '874044.00',
        'principal_paid' => '1427649.09',
        'quota_debt' => '0.00',
        'status' => AmortizationStatus::PAID->value,
    ]);

    $second = AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 2,
        'due_date' => '2025-12-05',
        'installment_value' => '2301693.09',
        'principal_value' => '1541925.58',
        'interest_value' => '759767.51',
        'extra_payment' => '0.00',
        'remaining_balance' => '75976750.91',
        'projected_balance' => '75976750.91',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '2301693.09',
        'status' => AmortizationStatus::UNPAID->value,
    ]);

    $updated = app(\App\Services\Financial\Transaction\ExtraordinaryPayment\Options\TermReductionService::class)
        ->apply($contract, $second, '10000000.00');

    expect($updated->remaining_balance)->toBe('65976750.91')
        ->and($updated->projected_balance)->toBe('65976750.91')
        ->and($updated->principal_value)->toBe('11541925.58');
});

it('does not reduce the same surplus twice when the installment already reflects the extraordinary payment', function () {
    Schema::create('contracts', function (Blueprint $table) {
        $table->id();
        $table->string('contract_number')->unique();
        $table->string('status')->default('activo');
        $table->decimal('interest_rate', 5, 2)->default(1.00);
        $table->timestamps();
    });

    Schema::create('amortization_installments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
        $table->integer('installment_number');
        $table->date('due_date');
        $table->string('status')->default('sin_pagar');
        $table->decimal('installment_value', 15, 2)->default(0);
        $table->decimal('extra_payment', 15, 2)->default(0);
        $table->decimal('interest_value', 15, 2)->default(0);
        $table->decimal('principal_value', 15, 2)->default(0);
        $table->decimal('interest_paid', 15, 2)->default(0);
        $table->decimal('principal_paid', 15, 2)->default(0);
        $table->decimal('quota_debt', 15, 2)->default(0);
        $table->decimal('remaining_balance', 15, 2)->default(0);
        $table->decimal('projected_balance', 15, 2)->default(0);
        $table->dateTime('payment_date')->nullable();
        $table->timestamps();
    });

    $contract = Contract::query()->create([
        'contract_number' => 'CT-EX-001',
        'status' => ContractStatus::ACTIVO->value,
        'interest_rate' => '1.00',
    ]);

    $installment = AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 1,
        'due_date' => '2025-02-01',
        'installment_value' => '4114428.60',
        'principal_value' => '3000000.00',
        'interest_value' => '1114428.60',
        'extra_payment' => '10000000.00',
        'remaining_balance' => '66171757.17',
        'projected_balance' => '66171757.17',
        'interest_paid' => '1114428.60',
        'principal_paid' => '3000000.00',
        'quota_debt' => '0.00',
        'status' => AmortizationStatus::PAID->value,
    ]);

    $updated = app(\App\Services\Financial\Transaction\ExtraordinaryPayment\Options\TermReductionService::class)
        ->apply($contract, $installment, '10000000.00');

    expect($updated->remaining_balance)->toBe('66171757.17')
        ->and($updated->extra_payment)->toBe('10000000.00');
});

it('does not subtract the scheduled principal twice when an exact payment settles the installment', function () {
    Schema::create('contracts', function (Blueprint $table) {
        $table->id();
        $table->string('contract_number')->unique();
        $table->string('status')->default('activo');
        $table->decimal('sale_price', 15, 2)->default(0);
        $table->decimal('down_payment_pactada', 15, 2)->default(0);
        $table->integer('term_months')->default(12);
        $table->decimal('interest_rate', 5, 2)->default(1.00);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('amortization_installments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
        $table->integer('installment_number');
        $table->date('due_date');
        $table->string('status')->default('sin_pagar');
        $table->decimal('installment_value', 15, 2)->default(0);
        $table->decimal('extra_payment', 15, 2)->default(0);
        $table->decimal('interest_value', 15, 2)->default(0);
        $table->decimal('principal_value', 15, 2)->default(0);
        $table->decimal('interest_paid', 15, 2)->default(0);
        $table->decimal('principal_paid', 15, 2)->default(0);
        $table->decimal('quota_debt', 15, 2)->default(0);
        $table->decimal('remaining_balance', 15, 2)->default(0);
        $table->decimal('projected_balance', 15, 2)->default(0);
        $table->dateTime('payment_date')->nullable();
        $table->timestamps();
    });

    Schema::create('transactions', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
        $table->string('transaction_type');
        $table->decimal('amount', 15, 2);
        $table->date('transaction_date');
        $table->string('payment_method');
        $table->timestamps();
    });

    $contract = Contract::query()->create([
        'contract_number' => 'CTR-EXACT-001',
        'status' => ContractStatus::ACTIVO->value,
        'sale_price' => '77116000.00',
        'down_payment_pactada' => '0.00',
        'term_months' => 12,
        'interest_rate' => '1.00',
    ]);

    $installment = AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 1,
        'due_date' => '2025-11-05',
        'payment_date' => null,
        'installment_value' => '1715402.83',
        'extra_payment' => '0.00',
        'interest_value' => '771160.00',
        'principal_value' => '944242.83',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1715402.83',
        'remaining_balance' => '76171757.17',
        'projected_balance' => '76171757.17',
        'status' => AmortizationStatus::UNPAID->value,
    ]);

    $service = app(RegularPaymentService::class);

    $impact = $service->calculatePaymentImpact($installment, '1715402.83', $contract);

    expect($impact['status'])->toBe(AmortizationStatus::PAID)
        ->and($impact['quota_debt'])->toBe('0.00')
        ->and($impact['interest_paid'])->toBe('771160.00')
        ->and($impact['principal_paid'])->toBe('944242.83')
        ->and($impact['excedente'])->toBe('0.00');

    $service->registerRegularPayment(new CreateTransactionDTO(
        contractId: $contract->id,
        amount: '1715402.83',
        transactionDate: Carbon::parse('2025-11-06'),
        paymentMethod: PaymentMethod::TRANSFER,
        transactionType: TransactionType::REGULAR_PAYMENT,
        installmentNumbers: [1],
    ));

    $installment->refresh();

    // El saldo proyectado ya descuenta los 944242.83 de capital de esta cuota; volver a
    // restarlos dejaría 75227514.34.
    expect($installment->remaining_balance)->toBe('76171757.17')
        ->and($installment->projected_balance)->toBe('76171757.17')
        ->and($installment->quota_debt)->toBe('0.00')
        ->and($installment->extra_payment)->toBe('0.00')
        ->and($installment->status)->toBe(AmortizationStatus::PAID->value)
        ->and(number_format((float) $installment->interest_paid, 2, '.', ''))->toBe('771160.00')
        ->and(number_format((float) $installment->principal_paid, 2, '.', ''))->toBe('944242.83');
});
