<?php

use App\Enums\AmortizationStatusEnum;
use App\Models\AmortizationPlan;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Collection\CascadeCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies a global payment only to the current installment and sends the surplus to term reduction', function () {
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
    $result = $service->process($contract->id, '1500.00');

    expect($result['amount_applied'])->toBe('1500.00')
        ->and($result['remaining_amount'])->toBe('0.00')
        ->and($current->fresh()->status)->toBe(AmortizationStatusEnum::PAID->value)
        ->and($current->fresh()->extra_payment)->toBe('500.00')
        ->and($current->fresh()->remaining_balance)->toBe('500.00')
        ->and($current->fresh()->projected_balance)->toBe('500.00')
        ->and($contract->amortizationInstallments()->where('installment_number', '>', 1)->count())->toBeGreaterThan(0)
        ->and($contract->amortizationInstallments()->where('installment_number', '>', 1)->first()->status)->toBe(AmortizationStatusEnum::PENDING->value);
});
