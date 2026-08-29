<?php

use App\Enums\AmortizationStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Collection\CascadeCollectionService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

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
        'status' => AmortizationStatus::PENDING->value,
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
        'status' => AmortizationStatus::PENDING->value,
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
        'status' => AmortizationStatus::PENDING->value,
    ]);

    $service = app(CascadeCollectionService::class);
    $result = $service->process($contract->id, '1500.00', null);

    $nextInstallment = $contract->amortizationInstallments()->where('installment_number', 2)->first();

    expect($result['amount_applied'])->toBe('1500.00')
        ->and($result['remaining_amount'])->toBe('0.00')
        ->and($current->fresh()->status)->toBe(AmortizationStatus::PAID)
        ->and($current->fresh()->quota_debt)->toBe('0.00')
        ->and(number_format((float) $current->fresh()->interest_paid, 2, '.', ''))->toBe('200.00')
        ->and(number_format((float) $current->fresh()->principal_paid, 2, '.', ''))->toBe('800.00')
        ->and($current->fresh()->remaining_balance)->toBe('1000.00')
        ->and($current->fresh()->projected_balance)->toBe('1000.00')
        ->and($nextInstallment->fresh()->status)->toBe(AmortizationStatus::PARTIAL)
        ->and($nextInstallment->fresh()->quota_debt)->toBe('500.00')
        ->and(number_format((float) $nextInstallment->fresh()->interest_paid, 2, '.', ''))->toBe('200.00')
        ->and(number_format((float) $nextInstallment->fresh()->principal_paid, 2, '.', ''))->toBe('300.00')
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
        'status' => AmortizationStatus::PENDING->value,
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
        'status' => AmortizationStatus::PENDING->value,
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
        'status' => AmortizationStatus::PENDING->value,
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
        ->and($month10->fresh()->status)->toBe(AmortizationStatus::PAID)
        ->and($month11->fresh()->status)->toBe(AmortizationStatus::PAID)
        ->and($month12->fresh()->status)->toBe(AmortizationStatus::PAID);
});

it('cascades leftover to unselected pending installments when no extraordinary option is provided', function () {
    $project = Project::create([
        'name' => 'Proyecto Cascade Extra',
        'description' => 'Proyecto de prueba',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '1000000003',
        'name' => 'Cliente Cascade Extra',
        'phone' => '3000000003',
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'C-103',
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-1003',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 3000,
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

    $first = $contract->amortizationInstallments()->create([
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
        'status' => AmortizationStatus::PENDING->value,
    ]);

    $second = $contract->amortizationInstallments()->create([
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
        'status' => AmortizationStatus::PENDING->value,
    ]);

    $third = $contract->amortizationInstallments()->create([
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
        'status' => AmortizationStatus::PENDING->value,
    ]);

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '1500.00',
        null,
        null,
        [$first->id],
    );

    expect($result['amount_applied'])->toBe('1500.00')
        ->and($first->fresh()->status)->toBe(AmortizationStatus::PAID)
        ->and($first->fresh()->quota_debt)->toBe('0.00')
        ->and($second->fresh()->status)->toBe(AmortizationStatus::PARTIAL)
        ->and($second->fresh()->quota_debt)->toBe('500.00')
        ->and($third->fresh()->status)->toBe(AmortizationStatus::PENDING)
        ->and($third->fresh()->quota_debt)->toBe('1000.00');
});

it('rejects a cascade payment when the contract is already fully settled', function () {
    $project = Project::create([
        'name' => 'Proyecto Saldado',
        'description' => 'Proyecto de prueba',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '1000000004',
        'name' => 'Cliente Saldado',
        'phone' => '3000000004',
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'C-104',
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-1004',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 1000,
        'down_payment_pactada' => 0,
        'term_months' => 1,
        'interest_rate' => 0,
        'start_date' => now()->subMonth()->toDateString(),
        'initial_payment_date' => now()->subMonth()->toDateString(),
        'first_installment_date' => now()->toDateString(),
        'regular_payment_start_date' => now()->toDateString(),
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 1,
        'due_date' => now()->toDateString(),
        'installment_value' => 1000,
        'principal_value' => 1000,
        'interest_value' => 0,
        'extra_payment' => 0,
        'remaining_balance' => 0,
        'projected_balance' => 0,
        'interest_paid' => 1000,
        'principal_paid' => 1000,
        'quota_debt' => 0,
        'status' => AmortizationStatus::PAID->value,
        'payment_date' => now()->toDateString(),
    ]);

    try {
        app(CascadeCollectionService::class)->process($contract->id, '100.00');
        expect(false)->toBeTrue('Se esperaba ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors()['amount'][0])->toBe('La obligación ya fue cumplida, no hay saldo pendiente para aplicar este pago.');
    }
});

