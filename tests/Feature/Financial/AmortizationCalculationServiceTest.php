<?php

use App\Models\Contract;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('calculates a fixed quota using bcmath without float drift', function () {
    $service = app(AmortizationCalculationService::class);

    $principal = '1000000.00';
    $quota = $service->calculateFixedQuota($principal, '1.00', 12);

    expect(preg_match('/^\d+\.\d{2}$/', $quota))->toBe(1)
        ->and(bccomp($quota, '88848.79', 2))->toBe(0);
});

it('builds a schedule whose last installment closes the residual balance exactly', function () {
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

    $contract = Contract::factory()->create([
        'sale_price' => '1000000.00',
        'down_payment_pactada' => '200000.00',
        'term_months' => 12,
        'interest_rate' => '1.00',
        'start_date' => '2026-08-01',
        'first_installment_date' => '2026-09-15',
        'regular_payment_start_date' => '2026-09-15',
    ]);

    $schedule = app(AmortizationCalculationService::class)->buildSchedule($contract);

    expect($schedule)->toHaveCount(13)
        ->and($schedule[array_key_last($schedule)]['remaining_balance'])->toBe('0.00');
});
