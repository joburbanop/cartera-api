<?php

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
        'description' => 'Fixture inicial partida',
        'location' => 'Cali',
        'status' => 'active',
    ]);
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => '34',
        'list_price' => '100000000.00',
    ]);

    $this->contract = Contract::factory()->create([
        'customer_id' => Customer::factory()->create()->id,
        'lot_id' => $lot->id,
        'contract_number' => 'SM-LOTE-34',
        'sale_price' => '100000000.00',
        'down_payment_pactada' => '9750000.00',
        'term_months' => 60,
        'interest_rate' => '1.00',
        'status' => 'activo',
        'start_date' => '2025-01-15',
    ]);

    $calc = app(AmortizationCalculationService::class);
    foreach ($calc->buildSchedule($this->contract) as $row) {
        AmortizationInstallment::query()->create(['contract_id' => $this->contract->id, ...$row]);
    }

    $due = Carbon::parse('2025-04-15');
    foreach ($this->contract->amortizationInstallments()
        ->where('installment_number', '>', 0)
        ->orderBy('installment_number')
        ->get() as $row
    ) {
        $row->update(['due_date' => $due->toDateString()]);
        $due->addMonthNoOverflow();
    }

    Transaction::query()->create([
        'contract_id' => $this->contract->id,
        'transaction_type' => TransactionType::DOWN_PAYMENT,
        'amount' => '5000000.00',
        'transaction_date' => '2025-01-08',
        'payment_method' => PaymentMethod::TRANSFER,
        'notes' => 'Recibo #0087 | Concepto: INICIAL',
    ]);
    Transaction::query()->create([
        'contract_id' => $this->contract->id,
        'transaction_type' => TransactionType::DOWN_PAYMENT,
        'amount' => '4750000.00',
        'transaction_date' => '2025-01-10',
        'payment_method' => PaymentMethod::TRANSFER,
        'notes' => 'Recibo #0191 | Concepto: INICIAL',
    ]);
    Transaction::query()->create([
        'contract_id' => $this->contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => '250000.00',
        'transaction_date' => '2025-01-10',
        'payment_method' => PaymentMethod::TRANSFER,
        'notes' => 'Recibo #0191 | Concepto: INICIAL',
    ]);

    $pmt = (string) $this->contract->amortizationInstallments()
        ->where('installment_number', 1)
        ->value('installment_value');
    Transaction::query()->create([
        'contract_id' => $this->contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => $pmt,
        'transaction_date' => '2025-04-15',
        'payment_method' => PaymentMethod::TRANSFER,
        'notes' => 'Recibo #0300 | Concepto: CUOTA 1',
    ]);
});

it('pliega el leftover INICIAL a #0 y no deja extra fantasma en #1', function () {
    $service = app(SanMiguelConceptReplayService::class);
    $service->foldInicialSplits($this->contract->fresh());
    $service->replay($this->contract->fresh());

    $contract = $this->contract->fresh(['installments', 'transactions']);
    $inicial = $contract->amortizationInstallments()->where('installment_number', 0)->first();
    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->first();
    $leftover = $contract->transactions
        ->first(fn (Transaction $tx) => str_contains((string) $tx->notes, '#0191')
            && (float) $tx->amount === 250000.0);

    expect($leftover->transaction_type)->toBe(TransactionType::DOWN_PAYMENT)
        ->and((float) $inicial->principal_paid)->toBe(10000000.0)
        ->and((float) $cuota1->extra_payment)->toBe(0.0)
        ->and($cuota1->status)->toBe(AmortizationStatus::PAID);
});

it('el overage con mora va a #1, no a sobre-pactada', function () {
    $service = app(SanMiguelConceptReplayService::class);
    $tx = Transaction::query()->create([
        'contract_id' => $this->contract->id,
        'transaction_type' => TransactionType::DOWN_PAYMENT,
        'amount' => '200000.00',
        'transaction_date' => '2025-04-20',
        'payment_method' => PaymentMethod::TRANSFER,
        'notes' => 'Recibo #0400 | Concepto: INICIAL',
    ]);

    $this->contract->amortizationInstallments()
        ->where('installment_number', 0)
        ->update([
            'principal_paid' => '9750000.00',
            'quota_debt' => '0.00',
            'status' => AmortizationStatus::PAID->value,
        ]);

    $service->applyInicialOverage(
        $this->contract->fresh(),
        $tx,
        '200000.00',
        Carbon::parse('2025-04-20')->startOfDay(),
    );

    $inicial = $this->contract->amortizationInstallments()->where('installment_number', 0)->first();
    $cuota1 = $this->contract->amortizationInstallments()->where('installment_number', 1)->first();

    expect((float) $inicial->principal_paid)->toBe(9750000.0)
        ->and((float) $cuota1->interest_paid + (float) $cuota1->principal_paid)->toBe(200000.0);
});

it('el dry-run de la oleada inicial no persiste', function () {
    $before = (float) $this->contract->amortizationInstallments()
        ->where('installment_number', 0)
        ->value('principal_paid');

    Artisan::call('san-miguel:reimpute-target-lots', [
        '--wave' => 'inicial',
        '--dry-run' => true,
        '--lot' => ['34'],
    ]);

    $after = (float) $this->contract->fresh()->amortizationInstallments()
        ->where('installment_number', 0)
        ->value('principal_paid');

    expect($after)->toBe($before);
});
