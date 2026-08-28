<?php

use App\Enums\AmortizationStatusEnum;
use App\Models\AmortizationPlan;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Collection\CascadeCollectionService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('continues the cascade to the next installment when no payment option is provided and applies the surplus as advance of cuotas', function () {
    $project = Project::create([
        'name' => 'Proyecto Cascade',
        'description' => 'Proyecto de prueba',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '1000000001',
        'name' => 'Cliente Cascade',
        'phone' => '3000000001',
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'C-101',
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-1001',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 1000,
        'down_payment_pactada' => 0,
        'term_months' => 3,
        'interest_rate' => 0,
        'start_date' => now()->subMonths(3)->toDateString(),
        'initial_payment_date' => now()->subMonths(3)->toDateString(),
        'first_installment_date' => now()->subMonths(2)->toDateString(),
        'regular_payment_start_date' => now()->subMonths(2)->toDateString(),
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    $current = $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 1,
        'due_date' => now()->subMonth()->toDateString(),
        'installment_value' => 1000,
        'principal_value' => 800,
        'interest_value' => 200,
        'extra_payment' => 0,
        'remaining_balance' => 1000,
        'projected_balance' => 1000,
        'interest_paid' => 0,
        'principal_paid' => 0,
        'quota_debt' => 1000,
        'status' => AmortizationStatusEnum::PENDING->value,
    ]);

    $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 2,
        'due_date' => now()->toDateString(),
        'installment_value' => 1000,
        'principal_value' => 800,
        'interest_value' => 200,
        'extra_payment' => 0,
        'remaining_balance' => 1000,
        'projected_balance' => 1000,
        'interest_paid' => 0,
        'principal_paid' => 0,
        'quota_debt' => 1000,
        'status' => AmortizationStatusEnum::PENDING->value,
    ]);

    $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 3,
        'due_date' => now()->addMonth()->toDateString(),
        'installment_value' => 1000,
        'principal_value' => 800,
        'interest_value' => 200,
        'extra_payment' => 0,
        'remaining_balance' => 1000,
        'projected_balance' => 1000,
        'interest_paid' => 0,
        'principal_paid' => 0,
        'quota_debt' => 1000,
        'status' => AmortizationStatusEnum::PENDING->value,
    ]);

    $service = app(CascadeCollectionService::class);
    $result = $service->process($contract->id, '1500.00', null);

    $nextInstallment = $contract->amortizationInstallments()->where('installment_number', 2)->first();

    expect($result['amount_applied'])->toBe('1500.00')
        ->and($result['remaining_amount'])->toBe('0.00')
        ->and($current->fresh()->status)->toBe(AmortizationStatusEnum::PAID->value)
        ->and($current->fresh()->quota_debt)->toBe('0.00')
        ->and($current->fresh()->remaining_balance)->toBe('1000.00')
        ->and($current->fresh()->projected_balance)->toBe('1000.00')
        ->and($nextInstallment->fresh()->status)->toBe(AmortizationStatusEnum::PARTIAL->value)
        ->and($nextInstallment->fresh()->quota_debt)->toBe('500.00')
        ->and($nextInstallment->fresh()->remaining_balance)->toBe('1000.00')
        ->and($nextInstallment->fresh()->projected_balance)->toBe('1000.00');
});

it('distributes selected installments sequentially and applies surplus only to the last selected installment', function () {
    $project = Project::create([
        'name' => 'Proyecto Seleccionado',
        'description' => 'Proyecto de prueba',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '1000000002',
        'name' => 'Cliente Seleccionado',
        'phone' => '3000000002',
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'C-102',
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-1002',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 5000,
        'down_payment_pactada' => 0,
        'term_months' => 12,
        'interest_rate' => 0,
        'start_date' => now()->subMonths(12)->toDateString(),
        'initial_payment_date' => now()->subMonths(12)->toDateString(),
        'first_installment_date' => now()->subMonths(11)->toDateString(),
        'regular_payment_start_date' => now()->subMonths(11)->toDateString(),
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    $month10 = $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 10,
        'due_date' => now()->subMonths(2)->toDateString(),
        'installment_value' => 1000,
        'principal_value' => 1000,
        'interest_value' => 0,
        'extra_payment' => 0,
        'remaining_balance' => 1000,
        'projected_balance' => 1000,
        'interest_paid' => 0,
        'principal_paid' => 0,
        'quota_debt' => 1000,
        'status' => AmortizationStatusEnum::PENDING->value,
    ]);

    $month11 = $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 11,
        'due_date' => now()->subMonth()->toDateString(),
        'installment_value' => 1000,
        'principal_value' => 1000,
        'interest_value' => 0,
        'extra_payment' => 0,
        'remaining_balance' => 1000,
        'projected_balance' => 1000,
        'interest_paid' => 0,
        'principal_paid' => 0,
        'quota_debt' => 1000,
        'status' => AmortizationStatusEnum::PENDING->value,
    ]);

    $month12 = $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 12,
        'due_date' => now()->toDateString(),
        'installment_value' => 1000,
        'principal_value' => 1000,
        'interest_value' => 0,
        'extra_payment' => 0,
        'remaining_balance' => 1000,
        'projected_balance' => 1000,
        'interest_paid' => 0,
        'principal_paid' => 0,
        'quota_debt' => 1000,
        'status' => AmortizationStatusEnum::PENDING->value,
    ]);

    $extraordinaryMock = \Mockery::mock(ExtraordinaryPaymentService::class);
    $extraordinaryMock
        ->shouldReceive('handle')
        ->once()
        ->withArgs(function ($handledContract, $handledInstallment, $surplusAmount, $paymentOption) use ($contract, $month12) {
            return $handledContract->id === $contract->id
                && (int) $handledInstallment->id === (int) $month12->id
                && $surplusAmount === '2000.00'
                && $paymentOption === 'reducir_plazo';
        })
        ->andReturn($month12);

    app()->instance(ExtraordinaryPaymentService::class, $extraordinaryMock);

    $service = app(CascadeCollectionService::class);

    $result = $service->process(
        $contract->id,
        '5000.00',
        'reducir_plazo',
        null,
        [$month10->id, $month11->id, $month12->id],
    );

    expect($result['amount_applied'])->toBe('5000.00')
        ->and($result['remaining_amount'])->toBe('0.00')
        ->and($result['installments'])->toHaveCount(3)
        ->and($result['installments'][0]['amount_applied'])->toBe('1000.00')
        ->and($result['installments'][1]['amount_applied'])->toBe('1000.00')
        ->and($result['installments'][2]['amount_applied'])->toBe('3000.00')
        ->and($month10->fresh()->quota_debt)->toBe('0.00')
        ->and($month11->fresh()->quota_debt)->toBe('0.00')
        ->and($month12->fresh()->quota_debt)->toBe('0.00')
        ->and($month10->fresh()->status)->toBe(AmortizationStatusEnum::PAID->value)
        ->and($month11->fresh()->status)->toBe(AmortizationStatusEnum::PAID->value)
        ->and($month12->fresh()->status)->toBe(AmortizationStatusEnum::PAID->value);
});
