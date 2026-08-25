<?php

use App\Models\Contract;
use App\Services\Financial\Amortization\AmortizationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('generates a valid amortization projection that settles the financed balance', function () {
    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('document_type');
        $table->string('document_number')->unique();
        $table->string('name');
        $table->string('phone')->nullable();
        $table->string('email')->nullable();
        $table->string('address')->nullable();
        $table->string('city')->nullable();
        $table->timestamps();
    });

    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('status')->default('activo');
        $table->timestamps();
    });

    Schema::create('lots', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->string('number');
        $table->decimal('area_m2', 10, 2)->default(100);
        $table->decimal('price_m2', 15, 2)->default(0);
        $table->decimal('list_price', 15, 2)->default(0);
        $table->string('status')->default('disponible');
        $table->string('type')->default('residential');
        $table->string('folio_matricula')->nullable();
        $table->string('ficha_catastral')->nullable();
        $table->text('boundaries_north')->nullable();
        $table->text('boundaries_south')->nullable();
        $table->text('boundaries_east')->nullable();
        $table->text('boundaries_west')->nullable();
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

    Schema::create('amortization_installments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('contract_id');
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

    $contract = Contract::factory()->create([
        'sale_price' => '1000000.00',
        'down_payment_pactada' => '200000.00',
        'term_months' => 12,
        'interest_rate' => '1.00',
        'start_date' => '2026-08-01',
        'first_installment_date' => '2026-09-15',
        'regular_payment_start_date' => '2026-09-15',
    ]);

    $rows = app(AmortizationService::class)->generateInitialProjection($contract);
    $totalQuotaValue = '0.00';

    foreach ($rows as $row) {
        $totalQuotaValue = bcadd($totalQuotaValue, (string) $row->installment_value, 2);
    }

    $principalFinanced = bcsub((string) $contract->sale_price, (string) $contract->down_payment_pactada, 2);

    expect($rows)->not->toBeEmpty()
        ->and($rows->last()->remaining_balance)->toBe('0.00')
        ->and(bccomp($totalQuotaValue, $principalFinanced, 2))->toBeGreaterThanOrEqual(-1)
        ->and(bccomp($totalQuotaValue, $principalFinanced, 2))->toBeLessThanOrEqual(1);
});
