<?php

use App\Enums\AllocationTarget;
use App\Enums\AmortizationStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Collection\CascadeCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('un pago de 2268400 sobre cuota 2268026 deja un solo extra de 374 a capital', function () {
    $project = Project::create([
        'name' => 'Proyecto extra 374',
        'description' => 'Sobrante a capital',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '1099000374',
        'name' => 'Cliente Extra 374',
        'phone' => '3000000374',
    ]);
    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'X-374',
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);
    $contract = Contract::create([
        'contract_number' => 'CT-EXTRA-374',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 136081560,
        'down_payment_pactada' => 0,
        'term_months' => 60,
        'interest_rate' => 0,
        'start_date' => now()->subMonth()->toDateString(),
        'initial_payment_date' => now()->subMonth()->toDateString(),
        'first_installment_date' => now()->toDateString(),
        'regular_payment_start_date' => now()->toDateString(),
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    $first = $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 1,
        'due_date' => now()->toDateString(),
        'installment_value' => '2268026.00',
        'principal_value' => '2268026.00',
        'interest_value' => '0.00',
        'extra_payment' => '0.00',
        'remaining_balance' => '133813534.00',
        'projected_balance' => '133813534.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '2268026.00',
        'status' => AmortizationStatus::PENDING->value,
    ]);
    $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 2,
        'due_date' => now()->addMonth()->toDateString(),
        'installment_value' => '2268026.00',
        'principal_value' => '2268026.00',
        'interest_value' => '0.00',
        'extra_payment' => '0.00',
        'remaining_balance' => '131545508.00',
        'projected_balance' => '131545508.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '2268026.00',
        'status' => AmortizationStatus::PENDING->value,
    ]);

    app(CascadeCollectionService::class)->process(
        $contract->id,
        '2268400.00',
        'reducir_plazo',
    );

    $first->refresh();
    $extras = $contract->amortizationInstallments()
        ->where('installment_number', '>', 0)
        ->where('extra_payment', '>', 0)
        ->get();

    expect($first->status)->toBe(AmortizationStatus::PAID)
        ->and($first->extra_payment)->toBe('374.00')
        ->and($extras)->toHaveCount(1)
        ->and($extras->first()->installment_number)->toBe(1);
});

it('abono_capital aplica el extra a capital, lo anota en el ledger y no adelanta la siguiente cuota', function () {
    $project = Project::create([
        'name' => 'Proyecto abono capital',
        'description' => 'Sobrante a capital con token explícito',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '1099000375',
        'name' => 'Cliente Abono Capital',
        'phone' => '3000000375',
    ]);
    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'X-375',
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);
    $contract = Contract::create([
        'contract_number' => 'CT-ABONO-CAP',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 136081560,
        'down_payment_pactada' => 0,
        'term_months' => 60,
        'interest_rate' => 0,
        'start_date' => now()->subMonth()->toDateString(),
        'initial_payment_date' => now()->subMonth()->toDateString(),
        'first_installment_date' => now()->toDateString(),
        'regular_payment_start_date' => now()->toDateString(),
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    $first = $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 1,
        'due_date' => now()->toDateString(),
        'installment_value' => '2268026.00',
        'principal_value' => '2268026.00',
        'interest_value' => '0.00',
        'extra_payment' => '0.00',
        'remaining_balance' => '133813534.00',
        'projected_balance' => '133813534.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '2268026.00',
        'status' => AmortizationStatus::PENDING->value,
    ]);
    $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 2,
        'due_date' => now()->addMonth()->toDateString(),
        'installment_value' => '2268026.00',
        'principal_value' => '2268026.00',
        'interest_value' => '0.00',
        'extra_payment' => '0.00',
        'remaining_balance' => '131545508.00',
        'projected_balance' => '131545508.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '2268026.00',
        'status' => AmortizationStatus::PENDING->value,
    ]);

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '2268400.00',
        'abono_capital',
    );

    $first->refresh();
    $second = $contract->amortizationInstallments()->where('installment_number', 2)->first();
    $transaction = $contract->transactions()->latest('id')->first();
    $capital = $transaction?->allocations()->where('target', AllocationTarget::CAPITAL)->first();

    expect($result['amount_applied'])->toBe('2268400.00')
        ->and($first->status)->toBe(AmortizationStatus::PAID)
        ->and($first->extra_payment)->toBe('374.00')
        ->and($second)->not->toBeNull()
        ->and($second->status)->toBe(AmortizationStatus::PENDING)
        ->and((float) $second->extra_payment)->toBe(0.0)
        ->and($second->quota_debt)->toBe('2268026.00')
        ->and($capital)->not->toBeNull()
        ->and((string) $capital->amount)->toBe('374.00');
});
