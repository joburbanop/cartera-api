<?php

use App\DTOs\CreateContractDTO;
use App\Http\Requests\StoreContractRequest;

it('maps independent preventa dates and preventa installments into the contract dto', function () {
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
        ->with('regular_payment_start_date')->andReturn('2026-09-01')
        ->shouldReceive('validated')
        ->with('preventa_installments_count')->andReturn(3)
        ->shouldReceive('validated')
        ->with('created_by')->andReturn(null);

    $dto = CreateContractDTO::fromRequest($request);

    expect($dto->initialPaymentDate)->toBe('2026-08-10')
        ->and($dto->regularPaymentStartDate)->toBe('2026-09-01')
        ->and($dto->preventaInstallmentsCount)->toBe(3);
});
