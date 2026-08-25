<?php

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\AmortizationPlan;
use App\Models\AmortizationInstallment;
use App\Models\AmortizationVersion;
use App\Models\Contract;
use App\Services\Financial\Transaction\RegularPayment\RegularPaymentService;
use App\Services\Financial\Transaction\TransactionService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('marks underpayment as partial while freezing the real amortization balance when contract is active', function () {
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
        ->and($result['remaining_balance'])->toBe('100.00')
        ->and($result['quota_debt'])->toBe('30.00');
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
        ->and($result['remaining_balance'])->toBe('87404400.00');
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
        ->and($result['remaining_balance'])->toBe('87024400.00');
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

it('keeps the real amortization balance separate from the outstanding quota debt even when the residual is larger', function () {
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
        ->and($result['remaining_balance'])->toBe('9000.00');
});

it('keeps the initial payment balance unchanged while reducing only the initial quota debt', function () {
    $contract = new Contract([
        'status' => ContractStatus::PREVENTA_INACTIVA->value,
    ]);

    $plan = new AmortizationPlan([
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

it('applies the LOTE 6 payment rules for partial, exact, and surplus payments', function () {
    $contract = new Contract([
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $plan = new AmortizationPlan([
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

    $plan = new AmortizationPlan([
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

it('keeps underpayment pending while contract is in preventa without reducing the credit balance', function () {
    $contract = new Contract([
        'status' => ContractStatus::PREVENTA_INACTIVA->value,
    ]);

    $plan = new AmortizationPlan([
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

    expect($result['status'])->toBe(AmortizationStatus::PARTIAL)
        ->and($result['quota_debt'])->toBe('80.00')
        ->and($result['remaining_balance'])->toBe('200.00');
});

it('creates v2 by freezing v1 rows and recalculating future installments after an extraordinary payment', function () {
    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('document_number')->unique();
        $table->string('document_type')->default('CC');
        $table->string('name');
        $table->string('phone')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('name')->unique();
        $table->string('description')->nullable();
        $table->string('location')->nullable();
        $table->string('status')->default('active');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('lots', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->string('number');
        $table->decimal('area_m2', 10, 2)->default(0);
        $table->decimal('price_m2', 15, 2)->default(0);
        $table->decimal('list_price', 15, 2)->default(0);
        $table->string('status')->default('disponible');
        $table->string('type')->default('residential');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('contracts', function (Blueprint $table) {
        $table->id();
        $table->string('contract_number')->unique();
        $table->unsignedBigInteger('customer_id');
        $table->unsignedBigInteger('lot_id');
        $table->string('seller_name')->nullable();
        $table->decimal('sale_price', 15, 2);
        $table->decimal('down_payment_pactada', 15, 2);
        $table->integer('term_months');
        $table->decimal('interest_rate', 5, 2)->default(1.00);
        $table->date('start_date');
        $table->date('initial_payment_date');
        $table->date('first_installment_date');
        $table->date('regular_payment_start_date');
        $table->integer('preventa_installments_count')->default(0);
        $table->string('status')->default('activo');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('amortization_plans', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
        $table->integer('version')->default(1);
        $table->integer('installment_number');
        $table->date('due_date');
        $table->decimal('installment_value', 15, 2);
        $table->decimal('principal_value', 15, 2);
        $table->decimal('interest_value', 15, 2);
        $table->decimal('extra_payment', 15, 2)->default(0);
        $table->decimal('remaining_balance', 15, 2);
        $table->decimal('interest_paid', 15, 2)->default(0);
        $table->decimal('principal_paid', 15, 2)->default(0);
        $table->decimal('quota_debt', 15, 2)->default(0);
        $table->string('status')->default('sin_pagar');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    $customer = \App\Models\Customer::query()->firstOrCreate(
        ['document_number' => 'EXTRA-200'],
        ['name' => 'Cliente v2', 'phone' => '3000000001', 'document_type' => 'CC']
    );

    $project = \App\Models\Project::query()->firstOrCreate(
        ['name' => 'Proyecto v2'],
        ['description' => 'Proyecto de prueba', 'location' => 'Prueba', 'status' => 'active']
    );

    $lot = \App\Models\Lot::query()->firstOrCreate(
        ['project_id' => $project->id, 'number' => 'EXTRA-8'],
        ['area_m2' => 150.00, 'price_m2' => 1000000.00, 'list_price' => 150000000.00, 'status' => 'disponible', 'type' => 'residential']
    );

    $contract = \App\Models\Contract::query()->create([
        'contract_number' => 'CTR-EXTRA-200',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Lina',
        'sale_price' => 90000000.00,
        'down_payment_pactada' => 0.00,
        'term_months' => 24,
        'interest_rate' => 1.00,
        'start_date' => '2025-10-05',
        'initial_payment_date' => '2025-10-05',
        'first_installment_date' => '2025-11-05',
        'regular_payment_start_date' => '2025-11-05',
        'preventa_installments_count' => 0,
        'status' => \App\Enums\ContractStatus::ACTIVO->value,
    ]);

    $historicalInitial = AmortizationPlan::query()->create([
        'contract_id' => $contract->id,
        'version' => 1,
        'installment_number' => 0,
        'due_date' => '2025-10-05',
        'installment_value' => '0.00',
        'principal_value' => '0.00',
        'interest_value' => '0.00',
        'extra_payment' => '0.00',
        'remaining_balance' => '77585711.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '0.00',
        'status' => AmortizationStatus::PAID->value,
        'is_active' => true,
    ]);

    $current = AmortizationPlan::query()->create([
        'contract_id' => $contract->id,
        'version' => 1,
        'installment_number' => 1,
        'due_date' => '2025-11-05',
        'installment_value' => '4114428.60',
        'principal_value' => '3000000.00',
        'interest_value' => '1114428.60',
        'extra_payment' => '0.00',
        'remaining_balance' => '77585711.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '4114428.60',
        'status' => AmortizationStatus::UNPAID->value,
        'is_active' => true,
    ]);

    app(\App\Services\Financial\Amortization\AmortizationRecalculatorService::class)
        ->applyExcess($contract, $current, '10000000.00', 'reducir_plazo');

    expect(AmortizationPlan::query()->where('contract_id', $contract->id)->where('version', 1)->where('is_active', false)->count())->toBeGreaterThan(0)
        ->and(AmortizationPlan::query()->where('contract_id', $contract->id)->where('version', 2)->exists())->toBeTrue()
        ->and(AmortizationPlan::query()->where('contract_id', $contract->id)->where('version', 2)->where('installment_number', 1)->first()->extra_payment)->toBe('10000000.00')
        ->and(AmortizationPlan::query()->where('contract_id', $contract->id)->where('version', 2)->where('installment_number', 1)->first()->remaining_balance)->toBe('67585711.00')
        ->and(AmortizationPlan::query()->where('contract_id', $contract->id)->where('version', 2)->where('installment_number', 2)->first()->remaining_balance)->toBe('64147139.51');
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
    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('document_number')->unique();
        $table->string('document_type')->default('CC');
        $table->string('name');
        $table->string('phone')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('name')->unique();
        $table->string('description')->nullable();
        $table->string('location')->nullable();
        $table->string('status')->default('active');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('lots', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->string('number');
        $table->decimal('area_m2', 10, 2)->default(0);
        $table->decimal('price_m2', 15, 2)->default(0);
        $table->decimal('list_price', 15, 2)->default(0);
        $table->string('status')->default('disponible');
        $table->string('type')->default('residential');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('contracts', function (Blueprint $table) {
        $table->id();
        $table->string('contract_number')->unique();
        $table->unsignedBigInteger('customer_id');
        $table->unsignedBigInteger('lot_id');
        $table->string('seller_name')->nullable();
        $table->decimal('sale_price', 15, 2);
        $table->decimal('down_payment_pactada', 15, 2);
        $table->integer('term_months');
        $table->decimal('interest_rate', 5, 2)->default(1.00);
        $table->date('start_date');
        $table->date('initial_payment_date');
        $table->date('first_installment_date');
        $table->date('regular_payment_start_date');
        $table->integer('preventa_installments_count')->default(0);
        $table->string('status')->default('activo');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('amortization_versions', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
        $table->unsignedBigInteger('transaction_id')->nullable();
        $table->integer('version_number')->default(1);
        $table->boolean('is_active')->default(true);
        $table->string('recalculation_type')->default('initial_projection');
        $table->timestamps();
    });

    Schema::create('amortization_installments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
        $table->unsignedBigInteger('amortization_version_id');
        $table->integer('installment_number');
        $table->date('due_date');
        $table->string('receipt_number')->nullable();
        $table->timestamp('payment_date')->nullable();
        $table->decimal('installment_value', 15, 2);
        $table->decimal('extra_payment', 15, 2)->default(0.00);
        $table->decimal('interest_value', 15, 2);
        $table->decimal('principal_value', 15, 2);
        $table->decimal('interest_paid', 15, 2)->default(0.00);
        $table->decimal('principal_paid', 15, 2)->default(0.00);
        $table->decimal('quota_debt', 15, 2)->default(0.00);
        $table->decimal('remaining_balance', 15, 2);
        $table->decimal('projected_balance', 15, 2);
        $table->string('status')->default('pending');
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

    $customer = \App\Models\Customer::query()->firstOrCreate(
        ['document_number' => 'EXACT-001'],
        ['name' => 'Cliente Exacto', 'phone' => '3000000020', 'document_type' => 'CC']
    );

    $project = \App\Models\Project::query()->firstOrCreate(
        ['name' => 'Proyecto Exacto'],
        ['description' => 'Proyecto de prueba', 'location' => 'Prueba', 'status' => 'active']
    );

    $lot = \App\Models\Lot::query()->firstOrCreate(
        ['project_id' => $project->id, 'number' => 'EXACT-15'],
        ['area_m2' => 100.00, 'price_m2' => 1000000.00, 'list_price' => 100000000.00, 'status' => 'disponible', 'type' => 'residential']
    );

    $contract = Contract::query()->create([
        'contract_number' => 'CTR-EXACT-001',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Lina',
        'sale_price' => 77116000.00,
        'down_payment_pactada' => 0.00,
        'term_months' => 12,
        'interest_rate' => 1.00,
        'start_date' => '2025-10-05',
        'initial_payment_date' => '2025-10-05',
        'first_installment_date' => '2025-11-05',
        'regular_payment_start_date' => '2025-11-05',
        'preventa_installments_count' => 0,
        'status' => ContractStatus::ACTIVO->value,
    ]);

    $version = AmortizationVersion::query()->create([
        'contract_id' => $contract->id,
        'version_number' => 1,
        'is_active' => true,
        'recalculation_type' => 'initial_projection',
    ]);

    $installment = AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'amortization_version_id' => $version->id,
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

    $dto = new CreateTransactionDTO(
        contractId: $contract->id,
        amount: '1715402.83',
        transactionDate: Carbon::parse('2025-11-06'),
        paymentMethod: PaymentMethod::TRANSFER,
        transactionType: TransactionType::REGULAR_PAYMENT,
        installmentNumbers: [1],
        paymentOption: null,
        receipt: null,
    );

    $service->registerRegularPayment($dto);
    $installment->refresh();

    expect($installment->remaining_balance)->toBe('76171757.17')
        ->and($installment->projected_balance)->toBe('76171757.17')
        ->and($installment->quota_debt)->toBe('0.00')
        ->and($installment->status)->toBe(AmortizationStatus::PAID->value);
});

it('stores the active amortization version and its installments in the relational schema', function () {
    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('document_number')->unique();
        $table->string('document_type')->default('CC');
        $table->string('name');
        $table->string('phone')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('name')->unique();
        $table->string('description')->nullable();
        $table->string('location')->nullable();
        $table->string('status')->default('active');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('lots', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->string('number');
        $table->decimal('area_m2', 10, 2)->default(0);
        $table->decimal('price_m2', 15, 2)->default(0);
        $table->decimal('list_price', 15, 2)->default(0);
        $table->string('status')->default('disponible');
        $table->string('type')->default('residential');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('contracts', function (Blueprint $table) {
        $table->id();
        $table->string('contract_number')->unique();
        $table->unsignedBigInteger('customer_id');
        $table->unsignedBigInteger('lot_id');
        $table->string('seller_name')->nullable();
        $table->decimal('sale_price', 15, 2);
        $table->decimal('down_payment_pactada', 15, 2);
        $table->integer('term_months');
        $table->decimal('interest_rate', 5, 2)->default(1.00);
        $table->date('start_date');
        $table->date('initial_payment_date');
        $table->date('first_installment_date');
        $table->date('regular_payment_start_date');
        $table->integer('preventa_installments_count')->default(0);
        $table->string('status')->default('activo');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('amortization_versions', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
        $table->unsignedBigInteger('transaction_id')->nullable();
        $table->integer('version_number')->default(1);
        $table->boolean('is_active')->default(true);
        $table->string('recalculation_type')->default('initial_projection');
        $table->timestamps();
    });

    Schema::create('amortization_installments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
        $table->unsignedBigInteger('amortization_version_id');
        $table->integer('installment_number');
        $table->date('due_date');
        $table->string('receipt_number')->nullable();
        $table->timestamp('payment_date')->nullable();
        $table->decimal('installment_value', 15, 2);
        $table->decimal('extra_payment', 15, 2)->default(0.00);
        $table->decimal('interest_value', 15, 2);
        $table->decimal('principal_value', 15, 2);
        $table->decimal('quota_debt', 15, 2)->default(0.00);
        $table->decimal('remaining_balance', 15, 2);
        $table->decimal('projected_balance', 15, 2);
        $table->string('status')->default('pending');
        $table->timestamps();
    });

    $customer = \App\Models\Customer::query()->firstOrCreate(
        ['document_number' => 'REL-NEW-1'],
        ['name' => 'Cliente Relacional', 'phone' => '3000000002', 'document_type' => 'CC']
    );

    $project = \App\Models\Project::query()->firstOrCreate(
        ['name' => 'Proyecto Relacional'],
        ['description' => 'Proyecto nuevo', 'location' => 'Prueba', 'status' => 'active']
    );

    $lot = \App\Models\Lot::query()->firstOrCreate(
        ['project_id' => $project->id, 'number' => 'REL-9'],
        ['area_m2' => 100.00, 'price_m2' => 1000000.00, 'list_price' => 100000000.00, 'status' => 'disponible', 'type' => 'residential']
    );

    $contract = \App\Models\Contract::query()->create([
        'contract_number' => 'CTR-REL-001',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Lina',
        'sale_price' => 100000000.00,
        'down_payment_pactada' => 10000000.00,
        'term_months' => 12,
        'interest_rate' => 1.00,
        'start_date' => '2025-10-05',
        'initial_payment_date' => '2025-10-05',
        'first_installment_date' => '2025-11-05',
        'regular_payment_start_date' => '2025-11-05',
        'preventa_installments_count' => 0,
        'status' => \App\Enums\ContractStatus::ACTIVO->value,
    ]);

    $version = \App\Models\AmortizationVersion::query()->create([
        'contract_id' => $contract->id,
        'version_number' => 1,
        'is_active' => true,
        'recalculation_type' => 'initial_projection',
    ]);

    $installment = \App\Models\AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'amortization_version_id' => $version->id,
        'installment_number' => 1,
        'due_date' => '2025-11-05',
        'payment_date' => '2025-11-06 12:00:00',
        'installment_value' => '4114428.60',
        'extra_payment' => '0.00',
        'interest_value' => '1114428.60',
        'principal_value' => '3000000.00',
        'quota_debt' => '0.00',
        'remaining_balance' => '67000000.00',
        'projected_balance' => '67000000.00',
        'status' => AmortizationStatus::PAID->value,
    ]);

    expect($contract->activeAmortizationVersion->id)->toBe($version->id)
        ->and($version->installments()->count())->toBe(1)
        ->and($installment->version->id)->toBe($version->id)
        ->and($installment->status)->toBe(AmortizationStatus::PAID->value);
});

it('creates a reduced-term version after an extraordinary payment without altering the historic rows', function () {
    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('document_number')->unique();
        $table->string('document_type')->default('CC');
        $table->string('name');
        $table->string('phone')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('name')->unique();
        $table->string('description')->nullable();
        $table->string('location')->nullable();
        $table->string('status')->default('active');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('lots', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->string('number');
        $table->decimal('area_m2', 10, 2)->default(0);
        $table->decimal('price_m2', 15, 2)->default(0);
        $table->decimal('list_price', 15, 2)->default(0);
        $table->string('status')->default('disponible');
        $table->string('type')->default('residential');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('contracts', function (Blueprint $table) {
        $table->id();
        $table->string('contract_number')->unique();
        $table->unsignedBigInteger('customer_id');
        $table->unsignedBigInteger('lot_id');
        $table->string('seller_name')->nullable();
        $table->decimal('sale_price', 15, 2);
        $table->decimal('down_payment_pactada', 15, 2);
        $table->integer('term_months');
        $table->decimal('interest_rate', 5, 2)->default(1.00);
        $table->date('start_date');
        $table->date('initial_payment_date');
        $table->date('first_installment_date');
        $table->date('regular_payment_start_date');
        $table->integer('preventa_installments_count')->default(0);
        $table->string('status')->default('activo');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('amortization_plans', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
        $table->integer('version')->default(1);
        $table->integer('installment_number');
        $table->date('due_date');
        $table->decimal('installment_value', 15, 2);
        $table->decimal('principal_value', 15, 2);
        $table->decimal('interest_value', 15, 2);
        $table->decimal('extra_payment', 15, 2)->default(0);
        $table->decimal('remaining_balance', 15, 2);
        $table->decimal('interest_paid', 15, 2)->default(0);
        $table->decimal('principal_paid', 15, 2)->default(0);
        $table->decimal('quota_debt', 15, 2)->default(0);
        $table->string('status')->default('sin_pagar');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    $customer = \App\Models\Customer::query()->firstOrCreate(
        ['document_number' => 'EXTRA-100'],
        ['name' => 'Cliente Extra', 'phone' => '3000000000', 'document_type' => 'CC']
    );

    $project = \App\Models\Project::query()->firstOrCreate(
        ['name' => 'Proyecto Extra'],
        ['description' => 'Proyecto de prueba', 'location' => 'Prueba', 'status' => 'active']
    );

    $lot = \App\Models\Lot::query()->firstOrCreate(
        ['project_id' => $project->id, 'number' => 'EXTRA-7'],
        ['area_m2' => 150.00, 'price_m2' => 1000000.00, 'list_price' => 150000000.00, 'status' => 'disponible', 'type' => 'residential']
    );

    $contract = \App\Models\Contract::query()->create([
        'contract_number' => 'CTR-EXTRA-001',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Lina',
        'sale_price' => 105196000.00,
        'down_payment_pactada' => 10519600.00,
        'term_months' => 60,
        'interest_rate' => 1.00,
        'start_date' => '2025-10-05',
        'initial_payment_date' => '2025-10-05',
        'first_installment_date' => '2025-11-05',
        'regular_payment_start_date' => '2025-11-05',
        'preventa_installments_count' => 0,
        'status' => \App\Enums\ContractStatus::ACTIVO->value,
    ]);

    AmortizationPlan::query()->create([
        'contract_id' => $contract->id,
        'version' => 1,
        'installment_number' => 0,
        'due_date' => '2025-10-05',
        'installment_value' => '10519600.00',
        'principal_value' => '10519600.00',
        'interest_value' => '0.00',
        'extra_payment' => '0.00',
        'remaining_balance' => '94676400.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '10519600.00',
        'status' => AmortizationStatus::UNPAID->value,
        'is_active' => true,
    ]);

    $current = AmortizationPlan::query()->create([
        'contract_id' => $contract->id,
        'version' => 1,
        'installment_number' => 1,
        'due_date' => '2025-11-05',
        'installment_value' => '2106024.00',
        'principal_value' => '208000.00',
        'interest_value' => '1898024.00',
        'extra_payment' => '0.00',
        'remaining_balance' => '94676400.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '2106024.00',
        'status' => AmortizationStatus::UNPAID->value,
        'is_active' => true,
    ]);

    $service = app(\App\Services\Financial\Amortization\AmortizationRecalculatorService::class);
    $service->applyExcess($contract, $current, '5000000.00', 'reducir_plazo');

    expect(AmortizationPlan::query()->where('contract_id', $contract->id)->where('version', 1)->where('installment_number', 0)->value('remaining_balance'))->toBe('94676400.00')
        ->and(AmortizationPlan::query()->where('contract_id', $contract->id)->where('version', 2)->exists())->toBeTrue();
});
