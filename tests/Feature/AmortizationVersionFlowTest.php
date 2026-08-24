<?php

use App\Models\AmortizationInstallment;
use App\Models\AmortizationVersion;
use App\Models\Contract;
use App\Services\Financial\Amortization\AmortizationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('reads only the active amortization version and its installments ordered by number', function () {
    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('lots', function (Blueprint $table) {
        $table->id();
        $table->string('number');
        $table->timestamps();
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
        $table->string('status')->default('preventa_inactiva');
        $table->timestamps();
    });

    Schema::create('amortization_versions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
        $table->foreignId('transaction_id')->nullable();
        $table->integer('version_number')->default(1);
        $table->boolean('is_active')->default(true);
        $table->string('recalculation_type')->default('initial_projection');
        $table->timestamps();
    });

    Schema::create('amortization_installments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('amortization_version_id')->constrained('amortization_versions')->onDelete('cascade');
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

    $customerId = DB::table('customers')->insertGetId(['name' => 'Cliente']);
    $lotId = DB::table('lots')->insertGetId(['number' => 'L-1']);

    $contract = Contract::create([
        'contract_number' => 'CTR-REG-001',
        'customer_id' => $customerId,
        'lot_id' => $lotId,
        'seller_name' => 'Ana',
        'sale_price' => 1000000.00,
        'down_payment_pactada' => 200000.00,
        'term_months' => 12,
        'interest_rate' => 1.00,
        'start_date' => '2026-08-01',
        'initial_payment_date' => '2026-08-10',
        'first_installment_date' => '2026-09-15',
        'regular_payment_start_date' => '2026-09-15',
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    $activeVersion = AmortizationVersion::create([
        'contract_id' => $contract->id,
        'version_number' => 1,
        'is_active' => true,
        'recalculation_type' => 'initial_projection',
    ]);

    $inactiveVersion = AmortizationVersion::create([
        'contract_id' => $contract->id,
        'version_number' => 2,
        'is_active' => false,
        'recalculation_type' => 'reduce_term',
    ]);

    AmortizationInstallment::create([
        'amortization_version_id' => $activeVersion->id,
        'installment_number' => 2,
        'due_date' => '2026-11-15',
        'installment_value' => 90000.00,
        'interest_value' => 1000.00,
        'principal_value' => 89000.00,
        'remaining_balance' => 500000.00,
        'projected_balance' => 500000.00,
        'status' => 'pending',
    ]);

    AmortizationInstallment::create([
        'amortization_version_id' => $activeVersion->id,
        'installment_number' => 1,
        'due_date' => '2026-10-15',
        'installment_value' => 90000.00,
        'interest_value' => 2000.00,
        'principal_value' => 88000.00,
        'remaining_balance' => 600000.00,
        'projected_balance' => 600000.00,
        'status' => 'pending',
    ]);

    AmortizationInstallment::create([
        'amortization_version_id' => $inactiveVersion->id,
        'installment_number' => 1,
        'due_date' => '2026-10-15',
        'installment_value' => 70000.00,
        'interest_value' => 1000.00,
        'principal_value' => 69000.00,
        'remaining_balance' => 700000.00,
        'projected_balance' => 700000.00,
        'status' => 'pending',
    ]);

    $service = app(AmortizationService::class);
    $rows = $service->getActiveInstallments($contract);

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('installment_number')->all())->toBe([1, 2])
        ->and($rows->pluck('amortization_version_id')->unique()->all())->toBe([$activeVersion->id]);
});

it('does not subtract the scheduled principal twice when the settled row already has its projected balance reduced', function () {
    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('lots', function (Blueprint $table) {
        $table->id();
        $table->string('number');
        $table->timestamps();
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
    });

    Schema::create('amortization_versions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
        $table->foreignId('transaction_id')->nullable();
        $table->integer('version_number')->default(1);
        $table->boolean('is_active')->default(true);
        $table->string('recalculation_type')->default('initial_projection');
        $table->timestamps();
    });

    Schema::create('amortization_installments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('amortization_version_id')->constrained('amortization_versions')->onDelete('cascade');
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

    $customerId = DB::table('customers')->insertGetId(['name' => 'Cliente cuota 1']);
    $lotId = DB::table('lots')->insertGetId(['number' => 'L-CUOTA-1']);

    $contract = Contract::create([
        'contract_number' => 'CTR-CUOTA-1-001',
        'customer_id' => $customerId,
        'lot_id' => $lotId,
        'seller_name' => 'Ana',
        'sale_price' => 77116000.00,
        'down_payment_pactada' => 0.00,
        'term_months' => 12,
        'interest_rate' => 1.00,
        'start_date' => '2026-08-01',
        'initial_payment_date' => '2026-08-10',
        'first_installment_date' => '2026-09-15',
        'regular_payment_start_date' => '2026-09-15',
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    $versionOne = AmortizationVersion::create([
        'contract_id' => $contract->id,
        'version_number' => 1,
        'is_active' => true,
        'recalculation_type' => 'initial_projection',
    ]);

    $current = AmortizationInstallment::create([
        'amortization_version_id' => $versionOne->id,
        'installment_number' => 1,
        'due_date' => '2026-09-15',
        'installment_value' => 944242.83,
        'extra_payment' => 0.00,
        'interest_value' => 0.00,
        'principal_value' => 944242.83,
        'quota_debt' => 944242.83,
        'remaining_balance' => 77116000.00,
        'projected_balance' => 76171757.17,
        'status' => 'pending',
    ]);

    $service = app(AmortizationService::class);
    $service->createReducedTermVersion($contract, $current, '10000000.00', 'reducir_plazo');

    $versionTwo = $contract->amortizationVersions()->where('version_number', 2)->firstOrFail();
    $paidRow = $versionTwo->installments()->where('installment_number', 1)->firstOrFail();

    expect($versionOne->fresh()->is_active)->toBeFalse()
        ->and($versionTwo->is_active)->toBeTrue()
        ->and($paidRow->principal_value)->toBe('10944242.83')
        ->and($paidRow->remaining_balance)->toBe('66171757.17')
        ->and($paidRow->projected_balance)->toBe('66171757.17');
});

it('creates a reduce-term v2 and subtracts only the extra amount from the projected balance of the settled row', function () {
    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('lots', function (Blueprint $table) {
        $table->id();
        $table->string('number');
        $table->timestamps();
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
    });

    Schema::create('amortization_versions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
        $table->foreignId('transaction_id')->nullable();
        $table->integer('version_number')->default(1);
        $table->boolean('is_active')->default(true);
        $table->string('recalculation_type')->default('initial_projection');
        $table->timestamps();
    });

    Schema::create('amortization_installments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('amortization_version_id')->constrained('amortization_versions')->onDelete('cascade');
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

    $customerId = DB::table('customers')->insertGetId(['name' => 'Cliente extrabono']);
    $lotId = DB::table('lots')->insertGetId(['number' => 'L-EXTRA']);

    $contract = Contract::create([
        'contract_number' => 'CTR-EXTRA-001',
        'customer_id' => $customerId,
        'lot_id' => $lotId,
        'seller_name' => 'Ana',
        'sale_price' => 100000000.00,
        'down_payment_pactada' => 0.00,
        'term_months' => 12,
        'interest_rate' => 1.00,
        'start_date' => '2026-08-01',
        'initial_payment_date' => '2026-08-10',
        'first_installment_date' => '2026-09-15',
        'regular_payment_start_date' => '2026-09-15',
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    $versionOne = AmortizationVersion::create([
        'contract_id' => $contract->id,
        'version_number' => 1,
        'is_active' => true,
        'recalculation_type' => 'initial_projection',
    ]);

    AmortizationInstallment::create([
        'amortization_version_id' => $versionOne->id,
        'installment_number' => 1,
        'due_date' => '2026-09-15',
        'installment_value' => 1715402.83,
        'extra_payment' => 0.00,
        'interest_value' => 700000.00,
        'principal_value' => 1015402.83,
        'quota_debt' => 1715402.83,
        'remaining_balance' => 75218071.92,
        'projected_balance' => 75218071.92,
        'status' => 'paid',
    ]);

    AmortizationInstallment::create([
        'amortization_version_id' => $versionOne->id,
        'installment_number' => 2,
        'due_date' => '2026-10-15',
        'installment_value' => 1715402.83,
        'extra_payment' => 0.00,
        'interest_value' => 730000.00,
        'principal_value' => 985402.83,
        'quota_debt' => 1715402.83,
        'remaining_balance' => 75218071.92,
        'projected_balance' => 75218071.92,
        'status' => 'paid',
    ]);

    $current = AmortizationInstallment::create([
        'amortization_version_id' => $versionOne->id,
        'installment_number' => 3,
        'due_date' => '2026-11-15',
        'installment_value' => 1715402.83,
        'extra_payment' => 0.00,
        'interest_value' => 752180.72,
        'principal_value' => 963222.11,
        'quota_debt' => 1715402.83,
        'remaining_balance' => 75218071.92,
        'projected_balance' => 75218071.92,
        'status' => 'pending',
    ]);

    $service = app(\App\Services\Financial\Amortization\AmortizationService::class);
    $service->createReducedTermVersion($contract, $current, '10000000.00', 'reducir_plazo');

    $versionTwo = $contract->amortizationVersions()->where('version_number', 2)->firstOrFail();
    $paidRow = $versionTwo->installments()->where('installment_number', 3)->firstOrFail();

    expect($versionOne->fresh()->is_active)->toBeFalse()
        ->and($versionTwo->is_active)->toBeTrue()
        ->and($versionTwo->recalculation_type)->toBe('reduce_term')
        ->and($paidRow->principal_value)->toBe('10963222.11')
        ->and($paidRow->remaining_balance)->toBe('65218071.92')
        ->and($paidRow->projected_balance)->toBe('65218071.92');
});
