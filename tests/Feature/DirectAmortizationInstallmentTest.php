<?php

use App\Models\AmortizationInstallment;
use App\Models\Contract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('binds amortization installments directly to the contract without versioning', function () {
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
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::create('amortization_installments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
        $table->integer('installment_number');
        $table->date('due_date');
        $table->decimal('installment_value', 15, 2);
        $table->decimal('interest_value', 15, 2);
        $table->decimal('principal_value', 15, 2);
        $table->decimal('remaining_balance', 15, 2);
        $table->decimal('projected_balance', 15, 2);
        $table->string('status')->default('pending');
        $table->timestamps();
    });

    $customerId = DB::table('customers')->insertGetId(['name' => 'Cliente directo']);
    $lotId = DB::table('lots')->insertGetId(['number' => 'L-DIRECT']);

    $contract = Contract::create([
        'contract_number' => 'CTR-DIRECT-001',
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

    $installment = AmortizationInstallment::create([
        'contract_id' => $contract->id,
        'installment_number' => 1,
        'due_date' => '2026-09-15',
        'installment_value' => 944242.83,
        'interest_value' => 700000.00,
        'principal_value' => 244242.83,
        'remaining_balance' => 76171757.17,
        'projected_balance' => 76171757.17,
        'status' => 'pending',
    ]);

    expect($contract->amortizationInstallments()->count())->toBe(1)
        ->and($contract->amortizationInstallments()->first()->id)->toBe($installment->id)
        ->and($installment->contract->id)->toBe($contract->id);
});
