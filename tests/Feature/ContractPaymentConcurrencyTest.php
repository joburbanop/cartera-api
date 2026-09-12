<?php

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Collection\CascadeCollectionService;
use App\Services\Collection\PaymentReversalService;
use App\Services\Collection\PreventaThenCascadeCollectionService;
use App\Services\Collection\SplitPaymentService;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use App\Services\Residual\ResidualCollectionService;
use App\Support\ContractFinancialLock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionClass;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function concurrencyContract(): Contract
{
    $suffix = (string) random_int(100000, 999999);

    $project = Project::query()->create([
        'name' => 'Proyecto Lock '.$suffix,
        'description' => 'Fixture Hueco #6',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => 'LK-'.$suffix,
    ]);

    $contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'sale_price' => '2000.00',
        'down_payment_pactada' => '0.00',
        'term_months' => 2,
        'interest_rate' => 0,
        'status' => 'activo',
        'start_date' => '2026-07-10',
        'first_installment_date' => '2026-08-10',
        'regular_payment_start_date' => '2026-08-10',
    ]);

    $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 1,
        'due_date' => '2026-08-10',
        'installment_value' => '1000.00',
        'principal_value' => '1000.00',
        'interest_value' => '0.00',
        'extra_payment' => '0.00',
        'remaining_balance' => '1000.00',
        'projected_balance' => '1000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000.00',
        'status' => AmortizationStatus::PENDING->value,
    ]);

    return $contract->fresh();
}

it('los servicios de cobro y reversa adquieren el lock del contrato por el helper único', function () {
    $helperSrc = (string) file_get_contents((new ReflectionClass(ContractFinancialLock::class))->getFileName());
    expect($helperSrc)->toContain('lockForUpdate()');

    $classes = [
        CascadeCollectionService::class,
        PreventaThenCascadeCollectionService::class,
        SplitPaymentService::class,
        DownPaymentService::class,
        ResidualCollectionService::class,
        PaymentReversalService::class,
    ];

    foreach ($classes as $class) {
        $src = (string) file_get_contents((new ReflectionClass($class))->getFileName());
        expect($src)->toContain('ContractFinancialLock::acquire');
    }
});

it('el cobro en cascada selecciona el contrato con lock dentro de la transacción', function () {
    $contract = concurrencyContract();
    $inTransaction = false;

    DB::listen(function ($query) use (&$inTransaction) {
        if (str_contains(strtolower($query->sql), 'from "contracts"')
            && str_contains(strtolower($query->sql), 'limit')
            && DB::transactionLevel() > 0
        ) {
            $inTransaction = true;
        }
    });

    app(CascadeCollectionService::class)->process(
        $contract->id,
        '1000.00',
        null,
        Carbon::parse('2026-09-10'),
    );

    expect($inTransaction)->toBeTrue();
});

it('dos cobros del mismo monto sobre la misma cuota se serializan: el segundo no duplica el recaudo', function () {
    $contract = concurrencyContract();
    $service = app(CascadeCollectionService::class);

    $first = $service->process(
        $contract->id,
        '1000.00',
        null,
        Carbon::parse('2026-09-10'),
    );

    $secondError = null;
    try {
        $service->process(
            $contract->id,
            '1000.00',
            null,
            Carbon::parse('2026-09-10'),
        );
    } catch (ValidationException $e) {
        $secondError = $e;
    }

    $installment = AmortizationInstallment::query()
        ->where('contract_id', $contract->id)
        ->where('installment_number', 1)
        ->firstOrFail();

    expect($first['amount_applied'])->toBe('1000.00')
        ->and($secondError)->toBeInstanceOf(ValidationException::class)
        ->and($secondError->errors()['amount'][0] ?? '')->toContain('obligación ya fue cumplida')
        ->and($installment->status)->toBe(AmortizationStatus::PAID)
        ->and((string) $installment->quota_debt)->toBe('0.00')
        ->and((string) $installment->principal_paid)->toBe('1000.00')
        ->and($contract->transactions()->where('transaction_type', 'regular_payment')->count())->toBe(1);
});
