<?php

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createQuotaContract(): Contract
{
    $project = Project::query()->create([
        'name' => 'Recalc Keeping Quota',
        'description' => 'Fixture',
        'location' => 'Cali',
        'status' => 'active',
    ]);

    return Contract::factory()->create([
        'customer_id' => Customer::factory(),
        'lot_id' => Lot::factory()->create(['project_id' => $project->id]),
        'sale_price' => '10000.00',
        'down_payment_pactada' => '0.00',
        'term_months' => 3,
        'interest_rate' => '0.00',
        'status' => 'activo',
        'start_date' => '2026-01-05',
        'first_installment_date' => '2026-02-05',
        'regular_payment_start_date' => '2026-02-05',
    ]);
}

function createQuotaRow(Contract $contract, int $number, string $remaining, string $pmt = '800.00'): AmortizationInstallment
{
    return $contract->amortizationInstallments()->create([
        'installment_number' => $number,
        'due_date' => sprintf('2026-%02d-05', $number + 1),
        'installment_value' => $pmt,
        'extra_payment' => '0.00',
        'interest_value' => '0.00',
        'principal_value' => $pmt,
        'quota_debt' => $pmt,
        'remaining_balance' => $remaining,
        'projected_balance' => $remaining,
        'status' => AmortizationStatus::PENDING->value,
    ]);
}

it('cierra la última cuota con el saldo residual, como buildSchedule', function () {
    $contract = createQuotaContract();
    createQuotaRow($contract, 1, '1000.00');
    $second = createQuotaRow($contract, 2, '200.00');
    $third = createQuotaRow($contract, 3, '0.00');

    app(AmortizationCalculationService::class)->recalculateFutureKeepingQuota($contract, 1);

    $second->refresh();
    $third->refresh();

    expect((string) $second->interest_value)->toBe('0.00')
        ->and((string) $second->principal_value)->toBe('800.00')
        ->and((string) $second->remaining_balance)->toBe('200.00')
        ->and((string) $second->installment_value)->toBe('800.00')
        ->and((string) $third->interest_value)->toBe('0.00')
        ->and((string) $third->principal_value)->toBe('200.00')
        ->and((string) $third->installment_value)->toBe('200.00')
        ->and((string) $third->quota_debt)->toBe('200.00')
        ->and((string) $third->remaining_balance)->toBe('0.00');
});

it('pone en cero las pendientes siguientes si el saldo ya es 0, sin borrar filas', function () {
    $contract = createQuotaContract();
    $first = createQuotaRow($contract, 1, '0.00');
    $second = createQuotaRow($contract, 2, '800.00');
    $third = createQuotaRow($contract, 3, '0.00');
    $secondId = (int) $second->id;
    $thirdId = (int) $third->id;

    app(AmortizationCalculationService::class)->recalculateFutureKeepingQuota($contract, 1);

    $second->refresh();
    $third->refresh();

    expect($contract->amortizationInstallments()->count())->toBe(3)
        ->and((int) $second->id)->toBe($secondId)
        ->and((int) $third->id)->toBe($thirdId)
        ->and((string) $second->interest_value)->toBe('0.00')
        ->and((string) $second->principal_value)->toBe('0.00')
        ->and((string) $second->remaining_balance)->toBe('0.00')
        ->and((string) $third->interest_value)->toBe('0.00')
        ->and((string) $third->principal_value)->toBe('0.00')
        ->and((string) $third->remaining_balance)->toBe('0.00')
        ->and((string) $first->fresh()->remaining_balance)->toBe('0.00');
});
