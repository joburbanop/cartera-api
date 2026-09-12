<?php

use App\Enums\AllocationTarget;
use App\Enums\AmortizationStatus;
use App\Enums\PaymentMethod;
use App\Enums\ResidualBalanceStatus;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\ContractResidualBalance;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Services\Residual\ResidualBackfillScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function backfillSmContract(string $contractNumber = 'SM-LOTE-99', string $downPayment = '0.00', int $regularCount = 1): Contract
{
    $suffix = substr(str_replace('.', '', uniqid('', true)), -8);

    $project = Project::create([
        'name' => 'San Miguel',
        'description' => 'Fixture backfill',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '200'.$suffix,
        'name' => 'Cliente Backfill',
        'phone' => '300'.$suffix,
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'BF-'.$suffix,
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => $contractNumber,
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 4000,
        'down_payment_pactada' => $downPayment,
        'term_months' => max(1, $regularCount),
        'interest_rate' => 0,
        'start_date' => now()->subMonths(3)->toDateString(),
        'initial_payment_date' => now()->subMonths(3)->toDateString(),
        'first_installment_date' => now()->subMonths(2)->toDateString(),
        'regular_payment_start_date' => now()->subMonths(2)->toDateString(),
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    if (bccomp($downPayment, '0.00', 2) > 0) {
        $contract->amortizationInstallments()->create([
            'contract_id' => $contract->id,
            'installment_number' => 0,
            'due_date' => now()->subMonths(3)->toDateString(),
            'installment_value' => $downPayment,
            'principal_value' => $downPayment,
            'interest_value' => 0,
            'extra_payment' => 0,
            'remaining_balance' => 2000,
            'projected_balance' => 2000,
            'interest_paid' => 0,
            'principal_paid' => 0,
            'quota_debt' => $downPayment,
            'status' => AmortizationStatus::PENDING->value,
        ]);
    }

    for ($number = 1; $number <= $regularCount; $number++) {
        $contract->amortizationInstallments()->create([
            'contract_id' => $contract->id,
            'installment_number' => $number,
            'due_date' => now()->addMonths($number)->toDateString(),
            'installment_value' => 1000,
            'principal_value' => 800,
            'interest_value' => 200,
            'extra_payment' => 0,
            'remaining_balance' => 1000,
            'projected_balance' => 1000,
            'interest_paid' => 0,
            'principal_paid' => 0,
            'quota_debt' => 1000,
            'status' => AmortizationStatus::PENDING->value,
        ]);
    }

    return $contract->fresh(['amortizationInstallments']);
}

function markPaid(AmortizationInstallment $installment, string $principalPaid, string $interestPaid): AmortizationInstallment
{
    $installment->update([
        'status' => AmortizationStatus::PAID->value,
        'quota_debt' => '0.00',
        'principal_paid' => $principalPaid,
        'interest_paid' => $interestPaid,
    ]);

    return $installment->fresh();
}

function recordInstallmentAllocation(Contract $contract, AmortizationInstallment $installment, string $amount, string $principal, string $interest): TransactionAllocation
{
    $tx = Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => $amount,
        'transaction_date' => now()->toDateString(),
        'payment_method' => PaymentMethod::TRANSFER,
        'notes' => 'Fixture backfill',
    ]);

    return TransactionAllocation::query()->create([
        'transaction_id' => $tx->id,
        'target' => AllocationTarget::INSTALLMENT,
        'amortization_installment_id' => $installment->id,
        'amount' => $amount,
        'principal' => $principal,
        'interest' => $interest,
    ]);
}

it('propone insert cuando allocations muestran faltante menor al closer', function () {
    $contract = backfillSmContract();
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    markPaid($installment, '800.00', '200.00');
    recordInstallmentAllocation($contract, $installment, '998.00', '800.00', '200.00');

    $row = app(ResidualBackfillScanner::class)->evaluate($contract, $installment->fresh());

    expect($row['decision'])->toBe(ResidualBackfillScanner::DECISION_INSERT)
        ->and($row['evidence_method'])->toBe(ResidualBackfillScanner::EVIDENCE_ALLOCATION_BOOK_MINUS_CASH)
        ->and($row['amount'])->toBe('2.00');
});

it('omite sin residual cuando el pago cerró en exacto', function () {
    $contract = backfillSmContract();
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    markPaid($installment, '800.00', '200.00');
    recordInstallmentAllocation($contract, $installment, '1000.00', '800.00', '200.00');

    $row = app(ResidualBackfillScanner::class)->evaluate($contract, $installment->fresh());

    expect($row['decision'])->toBe(ResidualBackfillScanner::DECISION_OMIT)
        ->and($row['omit_reason'])->toBe(ResidualBackfillScanner::OMIT_SIN_RESIDUAL)
        ->and($row['evidence_method'])->toBe(ResidualBackfillScanner::EVIDENCE_ALLOCATION_EXACTO);
});

it('no reconstruye si la cuota pagada no tiene allocations', function () {
    $contract = backfillSmContract();
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    markPaid($installment, '800.00', '200.00');

    $row = app(ResidualBackfillScanner::class)->evaluate($contract, $installment->fresh());

    expect($row['decision'])->toBe(ResidualBackfillScanner::DECISION_OMIT)
        ->and($row['omit_reason'])->toBe(ResidualBackfillScanner::OMIT_NO_RECONSTRUIBLE)
        ->and($row['evidence_method'])->toBe(ResidualBackfillScanner::EVIDENCE_SIN_ALLOCATIONS);
});

it('no reconstruye un faltante de 600 porque el closer no lo habría condonado', function () {
    $contract = backfillSmContract();
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    markPaid($installment, '800.00', '200.00');
    recordInstallmentAllocation($contract, $installment, '400.00', '800.00', '200.00');

    $row = app(ResidualBackfillScanner::class)->evaluate($contract, $installment->fresh());

    expect($row['decision'])->toBe(ResidualBackfillScanner::DECISION_OMIT)
        ->and($row['omit_reason'])->toBe(ResidualBackfillScanner::OMIT_NO_RECONSTRUIBLE)
        ->and($row['evidence_method'])->toBe(ResidualBackfillScanner::EVIDENCE_GAP_EXCEDE_CLOSER);
});

it('omite sin residual cuando principal_paid absorbió extra y el efectivo cubre el libro de allocations', function () {
    $contract = backfillSmContract();
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    markPaid($installment, '1800.00', '200.00');
    $installment->update(['extra_payment' => '1000.00']);
    recordInstallmentAllocation($contract, $installment, '1000.00', '800.00', '200.00');

    $row = app(ResidualBackfillScanner::class)->evaluate($contract, $installment->fresh());

    expect($row['decision'])->toBe(ResidualBackfillScanner::DECISION_OMIT)
        ->and($row['omit_reason'])->toBe(ResidualBackfillScanner::OMIT_SIN_RESIDUAL)
        ->and($row['evidence_method'])->toBe(ResidualBackfillScanner::EVIDENCE_ALLOCATION_CASH_CUBRE);
});

it('omite sin residual cuando el efectivo cubre o excede el libro', function () {
    $contract = backfillSmContract();
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    markPaid($installment, '800.00', '200.00');
    recordInstallmentAllocation($contract, $installment, '1000.40', '800.00', '200.00');

    $row = app(ResidualBackfillScanner::class)->evaluate($contract, $installment->fresh());

    expect($row['decision'])->toBe(ResidualBackfillScanner::DECISION_OMIT)
        ->and($row['omit_reason'])->toBe(ResidualBackfillScanner::OMIT_SIN_RESIDUAL)
        ->and($row['evidence_method'])->toBe(ResidualBackfillScanner::EVIDENCE_ALLOCATION_CASH_CUBRE);
});

it('reconstruye el residual de inicial desde el ledger de cuota inicial', function () {
    $contract = backfillSmContract('SM-LOTE-52-TEST', '20000000.00', 0);
    $initial = $contract->amortizationInstallments()->where('installment_number', 0)->firstOrFail();
    markPaid($initial, '20000000.00', '0.00');

    $tx = Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::DOWN_PAYMENT,
        'amount' => '19999550.00',
        'transaction_date' => now()->toDateString(),
        'payment_method' => PaymentMethod::TRANSFER,
        'notes' => 'Inicial incompleta closer',
    ]);
    TransactionAllocation::query()->create([
        'transaction_id' => $tx->id,
        'target' => AllocationTarget::DOWN_PAYMENT,
        'amortization_installment_id' => $initial->id,
        'amount' => '19999550.00',
        'principal' => '19999550.00',
        'interest' => '0.00',
    ]);

    $row = app(ResidualBackfillScanner::class)->evaluate($contract->fresh(), $initial->fresh());

    expect($row['decision'])->toBe(ResidualBackfillScanner::DECISION_INSERT)
        ->and($row['evidence_method'])->toBe(ResidualBackfillScanner::EVIDENCE_DOWN_PAYMENT_LEDGER)
        ->and($row['amount'])->toBe('450.00');
});

it('no duplica una fila que ya existe', function () {
    $contract = backfillSmContract();
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    markPaid($installment, '800.00', '200.00');
    recordInstallmentAllocation($contract, $installment, '998.00', '800.00', '200.00');
    ContractResidualBalance::query()->create([
        'contract_id' => $contract->id,
        'amortization_installment_id' => $installment->id,
        'amount' => '2.00',
        'status' => ResidualBalanceStatus::PENDIENTE,
    ]);

    $row = app(ResidualBackfillScanner::class)->evaluate($contract, $installment->fresh());

    expect($row['decision'])->toBe(ResidualBackfillScanner::DECISION_OMIT)
        ->and($row['omit_reason'])->toBe(ResidualBackfillScanner::OMIT_YA_EXISTE);
});

it('el dry-run del comando escribe CSV y no persiste', function () {
    $contract = backfillSmContract('SM-LOTE-88');
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    markPaid($installment, '800.00', '200.00');
    recordInstallmentAllocation($contract, $installment, '998.00', '800.00', '200.00');

    $this->artisan('residuals:backfill-san-miguel')
        ->assertSuccessful()
        ->expectsOutputToContain('Dry-run');

    expect(ContractResidualBalance::query()->count())->toBe(0);

    $csv = storage_path('app/reporte-backfill-residuales-sm-insert.csv');
    expect(file_exists($csv))->toBeTrue();
    $contents = file_get_contents($csv);
    expect($contents)->toContain('allocation_book_minus_cash')
        ->and($contents)->toContain('SM-LOTE-88')
        ->and($contents)->toContain('2.00');
});
