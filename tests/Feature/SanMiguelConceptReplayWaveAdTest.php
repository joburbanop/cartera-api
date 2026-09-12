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
use App\Services\Imports\SanMiguelConceptReplayService;
use App\Support\DownPaymentLedger;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $project = Project::query()->create([
        'name' => 'San Miguel',
        'description' => 'Fixture oleada A+D',
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
        'status' => 'preventa_inactiva',
        'start_date' => '2025-03-15',
    ]);

    $calc = app(AmortizationCalculationService::class);
    foreach ($calc->buildSchedule($this->contract) as $row) {
        AmortizationInstallment::query()->create(['contract_id' => $this->contract->id, ...$row]);
    }

    $this->contract->amortizationInstallments()
        ->where('installment_number', 0)
        ->update([
            'due_date' => '2025-03-15',
            'principal_paid' => '19800000.00',
            'quota_debt' => '200000.00',
            'status' => AmortizationStatus::PARTIAL->value,
        ]);

    $due = Carbon::parse('2025-04-15');
    foreach ($this->contract->amortizationInstallments()
        ->where('installment_number', '>', 0)
        ->orderBy('installment_number')
        ->get() as $row
    ) {
        $row->update(['due_date' => $due->toDateString()]);
        $due->addMonthNoOverflow();
    }

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

it('manda el sobrante del CUOTA 1 a mora #2, no a capital', function () {
    app(SanMiguelConceptReplayService::class)->replay($this->contract->fresh());

    $contract = $this->contract->fresh(['installments', 'transactions.allocations']);
    $cuota1 = $contract->transactions
        ->first(fn (Transaction $tx) => str_contains((string) $tx->notes, 'CUOTA 1'));

    expect((float) DownPaymentLedger::pending($contract))->toBe(0.0)
        ->and($cuota1)->not->toBeNull();

    $allocations = $cuota1->allocations->sortBy('id')->values();
    expect($allocations->contains(fn ($row) => $row->target === AllocationTarget::CAPITAL))->toBeFalse();

    $n2 = $contract->amortizationInstallments()->where('installment_number', 2)->first();
    expect((float) $n2->quota_debt)->toBeGreaterThan(0)
        ->and((float) $n2->interest_paid + (float) $n2->principal_paid)->toBe(184597.17)
        ->and((float) $n2->extra_payment)->toBe(0.0);

    $n1 = $contract->amortizationInstallments()->where('installment_number', 1)->first();
    expect($n1->status)->toBe(AmortizationStatus::PAID)
        ->and((float) $n1->extra_payment)->toBe(0.0);
});

it('el dry-run de la oleada A+D no persiste', function () {
    $beforeDebt = (float) $this->contract->amortizationInstallments()
        ->where('installment_number', 2)
        ->value('quota_debt');

    Artisan::call('san-miguel:reimpute-target-lots', [
        '--wave' => 'ad',
        '--dry-run' => true,
        '--lot' => ['3'],
    ]);

    $after = $this->contract->fresh(['installments']);
    expect((float) $after->amortizationInstallments()->where('installment_number', 2)->value('quota_debt'))
        ->toBe($beforeDebt)
        ->and((float) DownPaymentLedger::pending($after))->toBe(200000.0);
});
