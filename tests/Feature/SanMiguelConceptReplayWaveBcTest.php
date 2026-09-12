<?php

use App\Enums\AmortizationStatus;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Imports\SanMiguel\SanMiguelPaymentConceptParser;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use App\Services\Imports\SanMiguelConceptReplayService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $project = Project::query()->create([
        'name' => 'San Miguel',
        'description' => 'Fixture oleada B+C',
        'location' => 'Cali',
        'status' => 'active',
    ]);
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => '18',
        'list_price' => '50000000.00',
    ]);

    $this->contract = Contract::factory()->create([
        'customer_id' => Customer::factory()->create()->id,
        'lot_id' => $lot->id,
        'contract_number' => 'SM-LOTE-18',
        'sale_price' => '50000000.00',
        'down_payment_pactada' => '10000000.00',
        'term_months' => 60,
        'interest_rate' => '1.00',
        'status' => 'activo',
        'start_date' => '2025-01-15',
    ]);

    $calc = app(AmortizationCalculationService::class);
    foreach ($calc->buildSchedule($this->contract) as $row) {
        AmortizationInstallment::query()->create(['contract_id' => $this->contract->id, ...$row]);
    }

    Transaction::query()->create([
        'contract_id' => $this->contract->id,
        'transaction_type' => TransactionType::DOWN_PAYMENT,
        'amount' => '10000000.00',
        'transaction_date' => '2025-01-10',
        'payment_method' => PaymentMethod::TRANSFER,
        'notes' => 'Recibo #0001 | Concepto: CUOTA INICIAL',
    ]);
});

it('el parser conserva el rango y marca +ABONO (G3)', function () {
    $parsed = (new SanMiguelPaymentConceptParser)->parse('Recibo #0100 | Concepto: CUOTA 2 - 3 + ABONO CAPITAL');

    expect($parsed->kind)->toBe('range_plus_abono')
        ->and($parsed->numbers)->toBe([2, 3])
        ->and($parsed->hasPlusAbono)->toBeTrue()
        ->and($parsed->leftoverOption)->toBe('reducir_plazo');
});

it('aplica el rango nombrado y el sobrante a reducir_plazo (G3)', function () {
    $pmt = (string) $this->contract->amortizationInstallments()
        ->where('installment_number', 1)
        ->value('installment_value');
    $two = bcmul($pmt, '2', 2);
    $amount = bcadd($two, '250000.00', 2);

    Transaction::query()->create([
        'contract_id' => $this->contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => $amount,
        'transaction_date' => '2025-01-20',
        'payment_method' => PaymentMethod::TRANSFER,
        'notes' => 'Recibo #0100 | Concepto: CUOTA 1 - 2 + ABONO CAPITAL',
    ]);

    app(SanMiguelConceptReplayService::class)->replay($this->contract->fresh());

    $n1 = $this->contract->amortizationInstallments()->where('installment_number', 1)->first();
    $n2 = $this->contract->amortizationInstallments()->where('installment_number', 2)->first();

    expect($n1->status)->toBe(AmortizationStatus::PAID)
        ->and($n2->status)->toBe(AmortizationStatus::PAID)
        ->and((float) $n2->extra_payment)->toBe(250000.0)
        ->and((float) $n1->extra_payment)->toBe(0.0);
});

it('CUOTA N simple con monto mayor deja el resto en reducir_plazo (G4)', function () {
    $pmt = (string) $this->contract->amortizationInstallments()
        ->where('installment_number', 1)
        ->value('installment_value');
    $amount = bcadd($pmt, '80000.00', 2);

    Transaction::query()->create([
        'contract_id' => $this->contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => $amount,
        'transaction_date' => '2025-01-20',
        'payment_method' => PaymentMethod::TRANSFER,
        'notes' => 'Recibo #0200 | Concepto: CUOTA 1',
    ]);

    app(SanMiguelConceptReplayService::class)->replay($this->contract->fresh());

    $n1 = $this->contract->amortizationInstallments()->where('installment_number', 1)->first();
    expect($n1->status)->toBe(AmortizationStatus::PAID)
        ->and((float) $n1->extra_payment)->toBe(80000.0);
});

it('el dry-run de la oleada B+C no persiste', function () {
    $before = (float) $this->contract->amortizationInstallments()
        ->where('installment_number', 1)
        ->value('extra_payment');

    Artisan::call('san-miguel:reimpute-target-lots', [
        '--wave' => 'bc',
        '--dry-run' => true,
        '--lot' => ['18'],
    ]);

    $after = (float) $this->contract->fresh()->amortizationInstallments()
        ->where('installment_number', 1)
        ->value('extra_payment');

    expect($after)->toBe($before);
});
