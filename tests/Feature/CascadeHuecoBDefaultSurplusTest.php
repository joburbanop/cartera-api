<?php

use App\Enums\AllocationTarget;
use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Collection\CascadeCollectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function huecoBContract(string $suffix): Contract
{
    $project = Project::create([
        'name' => 'Proyecto Hueco B '.$suffix,
        'description' => 'Default sin seleccion',
        'location' => 'Bogota',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '8'.$suffix,
        'name' => 'Cliente Hueco B '.$suffix,
        'phone' => '301'.$suffix,
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'HB-'.$suffix,
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);

    return Contract::create([
        'contract_number' => 'CT-HB-'.$suffix,
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 4000,
        'down_payment_pactada' => 0,
        'term_months' => 4,
        'interest_rate' => 0,
        'start_date' => '2026-04-15',
        'initial_payment_date' => '2026-04-15',
        'first_installment_date' => '2026-05-15',
        'regular_payment_start_date' => '2026-05-15',
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);
}

function huecoBInstallment(Contract $contract, int $number, string $dueDate, string $remainingBalance): AmortizationInstallment
{
    return $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => $number,
        'due_date' => $dueDate,
        'installment_value' => '1000.00',
        'principal_value' => '1000.00',
        'interest_value' => '0.00',
        'extra_payment' => '0.00',
        'remaining_balance' => $remainingBalance,
        'projected_balance' => $remainingBalance,
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000.00',
        'status' => AmortizationStatus::PENDING->value,
    ]);
}

it('Hueco B caso 1: sin seleccion, siguiente no vencida, payment_option null no aplica en silencio — pide las 4 opciones', function () {
    $suffix = (string) random_int(100000, 999999);
    $contract = huecoBContract($suffix);
    $first = huecoBInstallment($contract, 1, '2026-05-15', '2000.00');
    huecoBInstallment($contract, 2, '2026-06-15', '1000.00');

    try {
        app(CascadeCollectionService::class)->process(
            $contract->id,
            '1500.00',
            null,
            Carbon::parse('2026-05-15'),
            [],
        );
        expect(false)->toBeTrue('Se esperaba ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_option'][0] ?? '')->toBe(CascadeCollectionService::SURPLUS_ACTION_REQUIRED);
    }

    $first->refresh();
    $second = $contract->amortizationInstallments()->where('installment_number', 2)->first();

    expect($contract->transactions()->count())->toBe(0)
        ->and($first->status)->toBe(AmortizationStatus::PENDING)
        ->and($first->quota_debt)->toBe('1000.00')
        ->and($second)->not->toBeNull()
        ->and($second->status)->toBe(AmortizationStatus::PENDING)
        ->and($second->quota_debt)->toBe('1000.00');
});

it('Hueco B caso 1 via drawer: sin seleccion, siguiente no vencida, default abono_capital pega el sobrante en #1 y no cierra #2', function () {
    $suffix = (string) random_int(100000, 999999);
    $contract = huecoBContract($suffix);
    $first = huecoBInstallment($contract, 1, '2026-05-15', '2000.00');
    huecoBInstallment($contract, 2, '2026-06-15', '1000.00');

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '1500.00',
        'abono_capital',
        Carbon::parse('2026-05-15'),
        [],
    );

    $first->refresh();
    $second = $contract->amortizationInstallments()->where('installment_number', 2)->first();
    $transaction = $contract->transactions()->latest('id')->first();
    $capital = $transaction?->allocations()->where('target', AllocationTarget::CAPITAL)->first();

    expect($result['amount_applied'])->toBe('1500.00')
        ->and($result['installments'])->toHaveCount(1)
        ->and($result['installments'][0]['installment_number'])->toBe(1)
        ->and($first->status)->toBe(AmortizationStatus::PAID)
        ->and($first->extra_payment)->toBe('500.00')
        ->and($second)->not->toBeNull()
        ->and($second->status)->toBe(AmortizationStatus::PENDING)
        ->and($second->quota_debt)->toBe('1000.00')
        ->and((float) $second->extra_payment)->toBe(0.0)
        ->and($capital)->not->toBeNull()
        ->and((string) $capital->amount)->toBe('500.00');
});

it('Hueco B caso 2: sin seleccion, siguiente SI vencida, sin opcion → sobrante va a #2 por FIFO de mora', function () {
    $suffix = (string) random_int(100000, 999999);
    $contract = huecoBContract($suffix);
    $first = huecoBInstallment($contract, 1, '2026-03-15', '3000.00');
    $second = huecoBInstallment($contract, 2, '2026-04-15', '2000.00');
    $third = huecoBInstallment($contract, 3, '2026-06-15', '1000.00');

    $result = app(CascadeCollectionService::class)->process(
        $contract->id,
        '1500.00',
        null,
        Carbon::parse('2026-05-15'),
        [],
    );

    $first->refresh();
    $second->refresh();
    $third->refresh();

    expect($result['amount_applied'])->toBe('1500.00')
        ->and($result['installments'])->toHaveCount(2)
        ->and($result['installments'][0]['installment_number'])->toBe(1)
        ->and($result['installments'][0]['amount_applied'])->toBe('1000.00')
        ->and($result['installments'][1]['installment_number'])->toBe(2)
        ->and($result['installments'][1]['amount_applied'])->toBe('500.00')
        ->and($first->status)->toBe(AmortizationStatus::PAID)
        ->and($first->extra_payment)->toBe('0.00')
        ->and($second->status)->toBe(AmortizationStatus::OVERDUE)
        ->and($second->quota_debt)->toBe('500.00')
        ->and($third->status)->toBe(AmortizationStatus::PENDING)
        ->and($third->quota_debt)->toBe('1000.00');
});
