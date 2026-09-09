<?php

use App\Enums\AllocationTarget;
use App\Enums\AmortizationStatus;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use App\Support\DownPaymentLedger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $project = Project::query()->create([
        'name' => 'San Miguel',
        'description' => 'Fixture lote 3',
        'location' => 'Cali',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => '3',
        'list_price' => '97116000.00',
    ]);

    $this->contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'contract_number' => 'SM-LOTE-3',
        'sale_price' => '97116000.00',
        'down_payment_pactada' => '20000000.00',
        'term_months' => 60,
        'interest_rate' => '1.00',
        'status' => 'activo',
        'start_date' => '2025-03-15',
    ]);

    $calc = app(AmortizationCalculationService::class);
    foreach ($calc->buildSchedule($this->contract) as $row) {
        AmortizationInstallment::query()->create(['contract_id' => $this->contract->id, ...$row]);
    }

    $this->contract->amortizationInstallments()
        ->where('installment_number', 0)
        ->update([
            'principal_paid' => '19800000.00',
            'quota_debt' => '200000.00',
            'status' => AmortizationStatus::PARTIAL->value,
        ]);

    foreach ([
        ['2024-12-28', '1000000.00', '0074'],
        ['2025-01-17', '16000000.00', '0094'],
        ['2025-02-27', '1000000.00', '0185'],
        ['2025-03-20', '600000.00', '0215'],
        ['2025-03-28', '1200000.00', '0221'],
    ] as [$date, $amount, $receipt]) {
        Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::DOWN_PAYMENT,
            'amount' => $amount,
            'transaction_date' => $date,
            'payment_method' => PaymentMethod::TRANSFER,
            'notes' => "Recibo #{$receipt} | Concepto: CUOTA INICIAL",
        ]);
    }

    Transaction::query()->create([
        'contract_id' => $this->contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => '2100000.00',
        'transaction_date' => '2025-05-30',
        'payment_method' => PaymentMethod::TRANSFER,
        'notes' => 'Recibo #0258-0289 | Concepto: CUOTA 1',
    ]);
});

it('cubre el faltante de inicial con el CUOTA 1 y manda el sobrante a capital, sin duplicar el movimiento', function () {
    expect((float) DownPaymentLedger::pending($this->contract))->toBe(200000.0);

    Artisan::call('san-miguel:reimpute-target-lots', ['--lot' => ['3']]);

    $contract = $this->contract->fresh(['installments', 'transactions.allocations']);
    $cuota1 = $contract->transactions
        ->first(fn (Transaction $tx) => str_contains((string) $tx->notes, 'CUOTA 1'));

    expect($contract->transactions)->toHaveCount(6)
        ->and((float) DownPaymentLedger::pending($contract))->toBe(0.0)
        ->and($cuota1)->not->toBeNull()
        ->and($cuota1->transaction_type)->toBe(TransactionType::REGULAR_PAYMENT)
        ->and((float) $cuota1->amount)->toBe(2100000.0);

    $allocations = $cuota1->allocations->sortBy('id')->values();
    expect($allocations)->toHaveCount(3)
        ->and($allocations[0]->target)->toBe(AllocationTarget::DOWN_PAYMENT)
        ->and((float) $allocations[0]->amount)->toBe(200000.0)
        ->and($allocations[1]->target)->toBe(AllocationTarget::INSTALLMENT)
        ->and((float) $allocations[1]->amount)->toBe(1715402.83)
        ->and((float) $allocations[1]->principal)->toBe(944242.83)
        ->and((float) $allocations[1]->interest)->toBe(771160.0)
        ->and($allocations[2]->target)->toBe(AllocationTarget::CAPITAL)
        ->and((float) $allocations[2]->amount)->toBe(184597.17)
        ->and((float) $allocations[2]->principal)->toBe(184597.17)
        ->and((float) $allocations[2]->interest)->toBe(0.0)
        ->and((float) $allocations->sum('amount'))->toBe(2100000.0);

    $inicial = $contract->amortizationInstallments()->where('installment_number', 0)->first();
    expect($inicial->status)->toBe(AmortizationStatus::PAID)
        ->and((float) $inicial->quota_debt)->toBe(0.0)
        ->and((float) $inicial->principal_paid)->toBe(20000000.0);
});

it('expone el reparto en la hoja de vida para ver detalles', function () {
    Artisan::call('san-miguel:reimpute-target-lots', ['--lot' => ['3']]);

    $row = $this->getJson("/api/contracts/{$this->contract->id}/life-sheet")
        ->assertOk()
        ->json('data.rows');

    $cuota1 = collect($row)->firstWhere('receipt_number', '0258-0289');

    expect($cuota1['concept'])->toBe('CUOTA 1')
        ->and($cuota1['amount'])->toBe('2100000.00')
        ->and($cuota1['allocations'])->toHaveCount(3)
        ->and($cuota1['allocations'][0]['target_label'])->toBe('Cuota inicial')
        ->and($cuota1['allocations'][0]['amount'])->toBe('200000.00')
        ->and($cuota1['allocations'][1]['target_label'])->toBe('Cuota regular')
        ->and($cuota1['allocations'][1]['amount'])->toBe('1715402.83')
        ->and($cuota1['allocations'][1]['installment_number'])->toBe(1)
        ->and($cuota1['allocations'][2]['target_label'])->toBe('Abono a capital')
        ->and($cuota1['allocations'][2]['amount'])->toBe('184597.17')
        ->and($cuota1['allocations'][2]['installment_number'])->toBeNull();
});

it('es idempotente: segunda corrida no crea transacciones ni cambia el recaudo', function () {
    Artisan::call('san-miguel:reimpute-target-lots', ['--lot' => ['3']]);
    Artisan::call('san-miguel:reimpute-target-lots', ['--lot' => ['3']]);

    $contract = $this->contract->fresh(['transactions.allocations']);
    $cuota1 = $contract->transactions
        ->first(fn (Transaction $tx) => str_contains((string) $tx->notes, 'CUOTA 1'));

    expect($contract->transactions)->toHaveCount(6)
        ->and((float) $contract->transactions->sum('amount'))->toBe(21900000.0)
        ->and($cuota1->allocations)->toHaveCount(3)
        ->and((float) DownPaymentLedger::pending($contract))->toBe(0.0);
});

it('deja rastro en los regulares que reaplica por cascada sobre la tx existente', function () {
    Transaction::query()->create([
        'contract_id' => $this->contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => '1715403.00',
        'transaction_date' => '2025-06-09',
        'payment_method' => PaymentMethod::TRANSFER,
        'notes' => 'Recibo #0308 | Concepto: CUOTA 2',
    ]);

    Artisan::call('san-miguel:reimpute-target-lots', ['--lot' => ['3']]);

    $contract = $this->contract->fresh(['transactions.allocations']);
    $cuota2 = $contract->transactions
        ->first(fn (Transaction $tx) => str_contains((string) $tx->notes, 'CUOTA 2'));

    expect($contract->transactions)->toHaveCount(7)
        ->and($cuota2)->not->toBeNull()
        ->and($cuota2->allocations)->not->toBeEmpty()
        ->and((float) $cuota2->allocations->sum('amount'))->toBe(1715403.0);
});
