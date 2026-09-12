<?php

use App\Enums\AmortizationStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Collection\CascadeCollectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function overdueGateContract(): Contract
{
    $project = Project::create([
        'name' => 'Proyecto Atrasadas Extra',
        'description' => 'Proyecto de prueba',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '1000000200',
        'name' => 'Cliente Atrasadas Extra',
        'phone' => '3000000200',
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'E-201',
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-OVERDUE-GATE-001',
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

    $rows = [
        1 => ['due' => now()->subMonths(2), 'status' => AmortizationStatus::OVERDUE, 'remaining' => 3000],
        2 => ['due' => now()->subMonth(), 'status' => AmortizationStatus::OVERDUE, 'remaining' => 2000],
        3 => ['due' => now(), 'status' => AmortizationStatus::PENDING, 'remaining' => 1000],
    ];

    foreach ($rows as $number => $row) {
        $contract->amortizationInstallments()->create([
            'contract_id' => $contract->id,
            'installment_number' => $number,
            'due_date' => $row['due']->toDateString(),
            'installment_value' => 1000,
            'principal_value' => 1000,
            'interest_value' => 0,
            'extra_payment' => 0,
            'remaining_balance' => $row['remaining'],
            'projected_balance' => $row['remaining'],
            'interest_paid' => 0,
            'principal_paid' => 0,
            'quota_debt' => 1000,
            'status' => $row['status']->value,
        ]);
    }

    return $contract;
}

it('con reducir_plazo aplica FIFO a la mora y no rechaza si el monto no cubre todas las atrasadas', function () {
    $contract = overdueGateContract();
    $target = $contract->amortizationInstallments()->where('installment_number', 3)->first();

    app(CascadeCollectionService::class)->process(
        $contract->id,
        '1500.00',
        'reducir_plazo',
        Carbon::parse(now()->toDateString()),
        [(int) $target->id],
    );

    $first = $contract->amortizationInstallments()->where('installment_number', 1)->first();
    $second = $contract->amortizationInstallments()->where('installment_number', 2)->first();
    $third = $contract->amortizationInstallments()->where('installment_number', 3)->first();

    expect($first->status)->toBe(AmortizationStatus::PAID)
        ->and($first->quota_debt)->toBe('0.00')
        ->and($second->quota_debt)->toBe('500.00')
        ->and($second->status)->toBe(AmortizationStatus::OVERDUE)
        ->and($third->status)->toBe(AmortizationStatus::PENDING)
        ->and($third->extra_payment)->toBe('0.00');
});
