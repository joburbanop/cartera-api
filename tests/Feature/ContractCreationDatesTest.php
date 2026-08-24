<?php

use App\DTOs\CreateContractDTO;
use App\Http\Requests\StoreContractRequest;
use App\Models\Contract;
use App\Services\Financial\Amortization\AmortizationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('maps first installment date into the contract dto', function () {
    $request = Mockery::mock(StoreContractRequest::class);

    $request->shouldReceive('validated')
        ->with('contract_number')->andReturn('PROM-2026-001')
        ->shouldReceive('validated')
        ->with('customer_id')->andReturn(1)
        ->shouldReceive('validated')
        ->with('lot_id')->andReturn(2)
        ->shouldReceive('validated')
        ->with('seller_name')->andReturn('Ana')
        ->shouldReceive('validated')
        ->with('sale_price')->andReturn(250000000)
        ->shouldReceive('validated')
        ->with('down_payment_pactada')->andReturn(50000000)
        ->shouldReceive('validated')
        ->with('term_months')->andReturn(24)
        ->shouldReceive('validated')
        ->with('interest_rate')->andReturn(1.2)
        ->shouldReceive('validated')
        ->with('start_date')->andReturn('2026-08-01')
        ->shouldReceive('validated')
        ->with('initial_payment_date')->andReturn('2026-08-10')
        ->shouldReceive('validated')
        ->with('first_installment_date')->andReturn('2026-10-15')
        ->shouldReceive('validated')
        ->with('regular_payment_start_date')->andReturn('2026-10-15')
        ->shouldReceive('validated')
        ->with('preventa_installments_count')->andReturn(3)
        ->shouldReceive('validated')
        ->with('created_by')->andReturn(null);

    $dto = CreateContractDTO::fromRequest($request);

    expect($dto->firstInstallmentDate)->toBe('2026-10-15')
        ->and($dto->initialPaymentDate)->toBe('2026-08-10')
        ->and($dto->regularPaymentStartDate)->toBe('2026-10-15')
        ->and($dto->preventaInstallmentsCount)->toBe(3);
});

it('requires a valid first installment date in the contract request', function () {
    $rules = (new StoreContractRequest())->rules();

    expect($rules['first_installment_date'] ?? '')
        ->toContain('required')
        ->toContain('date');
});

it('allows contract creation without customer_id when customer information is provided for auto-registration', function () {
    Schema::create('lots', function (Blueprint $table) {
        $table->id();
        $table->string('number')->nullable();
        $table->timestamps();
    });

    DB::table('lots')->insert(['id' => 10, 'number' => 'LOT-10']);

    Schema::create('contracts', function (Blueprint $table) {
        $table->id();
        $table->string('contract_number')->unique();
        $table->unsignedBigInteger('customer_id')->nullable();
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
        $table->softDeletes();
    });

    $validator = Validator::make([
        'contract_number' => 'CTR-AUTO-CLIENTE-001',
        'lot_id' => 10,
        'seller_name' => 'Ana',
        'sale_price' => 250000000,
        'down_payment_pactada' => 50000000,
        'term_months' => 24,
        'interest_rate' => 1.00,
        'start_date' => '2026-08-01',
        'initial_payment_date' => '2026-08-10',
        'first_installment_date' => '2026-10-15',
        'regular_payment_start_date' => '2026-10-15',
        'preventa_installments_count' => 2,
        'customer_name' => 'Juan Pérez (Prueba)',
        'customer_document' => '99999999',
        'customer_phone' => '3000000000',
        'customer_email' => 'juan.prueba@example.com',
    ], (new StoreContractRequest())->rules());

    expect($validator->passes())->toBeTrue();
});

it('calculates regular installment due dates from the first installment date', function () {
    $contract = new Contract([
        'start_date' => '2026-08-23',
        'first_installment_date' => '2026-10-15',
    ]);

    $service = new AmortizationService();

    expect($service->getRegularInstallmentDueDate($contract, 1)->toDateString())->toBe('2026-10-15')
        ->and($service->getRegularInstallmentDueDate($contract, 2)->toDateString())->toBe('2026-11-15')
        ->and($service->getRegularInstallmentDueDate($contract, 3)->toDateString())->toBe('2026-12-15');
});

it('allows reusing a lot when the previous contract was rescinded or soft deleted', function () {
    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    Schema::create('lots', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
    });

    Schema::create('contracts', function (Blueprint $table) {
        $table->id();
        $table->string('contract_number');
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
        $table->unsignedBigInteger('created_by')->nullable();
        $table->unsignedBigInteger('updated_by')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    $customerId = DB::table('customers')->insertGetId(['name' => 'Cliente']);
    DB::table('lots')->insert(['id' => 99, 'name' => 'Lote 99']);

    Contract::query()->create([
        'contract_number' => 'CTR-RESCINDIDO-1',
        'customer_id' => $customerId,
        'lot_id' => 99,
        'seller_name' => 'Ana',
        'sale_price' => 200000000,
        'down_payment_pactada' => 50000000,
        'term_months' => 24,
        'interest_rate' => 1.00,
        'start_date' => '2026-08-01',
        'initial_payment_date' => '2026-08-10',
        'first_installment_date' => '2026-10-15',
        'regular_payment_start_date' => '2026-10-15',
        'preventa_installments_count' => 2,
        'status' => 'rescindido',
        'deleted_at' => now(),
        'created_by' => $customerId,
        'updated_by' => $customerId,
    ]);

    $validator = Validator::make([
        'contract_number' => 'CTR-RESCINDIDO-2',
        'customer_id' => $customerId,
        'lot_id' => 99,
        'seller_name' => 'Ana',
        'sale_price' => 210000000,
        'down_payment_pactada' => 55000000,
        'term_months' => 36,
        'interest_rate' => 1.00,
        'start_date' => '2026-08-20',
        'initial_payment_date' => '2026-08-27',
        'first_installment_date' => '2026-10-15',
        'regular_payment_start_date' => '2026-10-15',
        'preventa_installments_count' => 2,
    ], (new StoreContractRequest())->rules());

    expect($validator->passes())->toBeTrue();
});
