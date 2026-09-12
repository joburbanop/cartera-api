<?php

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\PaymentMethod;
use App\Enums\ResidualBalanceStatus;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\ContractResidualBalance;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Collection\CascadeCollectionService;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use App\Services\Financial\Transaction\InstallmentPaymentAllocator;
use App\Services\Residual\ResidualBalanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function residualContract(string $status = 'activo', int $regularCount = 1, string $downPayment = '0.00'): Contract
{
    $project = Project::create([
        'name' => 'Proyecto Residual',
        'description' => 'Fixture',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '1000000999',
        'name' => 'Cliente Residual',
        'phone' => '3000000999',
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'R-101',
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => $status === 'preventa_inactiva' ? 'preventa' : 'disponible',
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-RESIDUAL-001',
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
        'status' => $status,
    ]);

    if ($status === ContractStatus::PREVENTA_INACTIVA->value) {
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

    return $contract->fresh(['lot', 'installments']);
}

it('clasifica residual menor con techo 5000 inclusive y constantes propias', function () {
    $service = new ResidualBalanceService;

    expect($service->isMinorResidual('2.00'))->toBeTrue()
        ->and($service->isMinorResidual('5000.00'))->toBeTrue()
        ->and($service->isMinorResidual('5000.01'))->toBeFalse()
        ->and($service->isMinorResidual('0.00'))->toBeFalse()
        ->and(ResidualBalanceService::COLLECTIBLE_THRESHOLD)->toBe('500.00')
        ->and(ResidualBalanceService::MINOR_RESIDUAL_CAP)->toBe('5000.00');
});

it('mueve un residual de 2 condonado a contract_residual_balances y deja la cuota pagada', function () {
    $contract = residualContract();
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();

    app(InstallmentPaymentAllocator::class)->applyToInstallment(
        $installment,
        '998.00',
        Carbon::parse(now()->toDateString()),
        $contract,
    );

    $installment->refresh();
    $row = ContractResidualBalance::query()->where('amortization_installment_id', $installment->id)->first();

    expect($installment->quota_debt)->toBe('0.00')
        ->and($installment->status)->toBe(AmortizationStatus::PAID)
        ->and($row)->not->toBeNull()
        ->and(number_format((float) $row->amount, 2, '.', ''))->toBe('2.00')
        ->and($row->status)->toBe(ResidualBalanceStatus::PENDIENTE)
        ->and(app(ResidualBalanceService::class)->pendingSum($contract->id))->toBe('2.00')
        ->and(app(ResidualBalanceService::class)->isCollectible($contract->id))->toBeFalse();
});

it('no registra residual cuando el pago cierra la cuota en exacto', function () {
    $contract = residualContract();
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();

    app(InstallmentPaymentAllocator::class)->applyToInstallment(
        $installment,
        '1000.00',
        Carbon::parse(now()->toDateString()),
        $contract,
    );

    expect(ContractResidualBalance::query()->count())->toBe(0);
});

it('no registra residual cuando el closer de 500 no cierra la cuota', function () {
    $contract = residualContract();
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();

    app(InstallmentPaymentAllocator::class)->applyToInstallment(
        $installment,
        '500.00',
        Carbon::parse(now()->toDateString()),
        $contract,
    );

    $installment->refresh();

    expect($installment->quota_debt)->toBe('500.00')
        ->and($installment->status)->not->toBe(AmortizationStatus::PAID)
        ->and(ContractResidualBalance::query()->count())->toBe(0);
});

it('acumula el SUM de residuales pendientes y habilita cobro al llegar a 500', function () {
    $contract = residualContract(regularCount: 2);
    $first = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    $second = $contract->amortizationInstallments()->where('installment_number', 2)->firstOrFail();
    $allocator = app(InstallmentPaymentAllocator::class);
    $date = Carbon::parse(now()->toDateString());

    $allocator->applyToInstallment($first, '750.00', $date, $contract);
    $allocator->applyToInstallment($second, '750.00', $date, $contract);

    $service = app(ResidualBalanceService::class);

    expect($service->pendingSum($contract->id))->toBe('500.00')
        ->and($service->isCollectible($contract->id))->toBeTrue()
        ->and(ContractResidualBalance::query()->where('contract_id', $contract->id)->count())->toBe(2);
});

it('es idempotente: no duplica el residual de la misma cuota', function () {
    $contract = residualContract();
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    $allocator = app(InstallmentPaymentAllocator::class);
    $date = Carbon::parse(now()->toDateString());

    $allocator->applyToInstallment($installment, '998.00', $date, $contract);
    $allocator->applyToInstallment($installment->fresh(), '0.00', $date, $contract);

    expect(ContractResidualBalance::query()->where('amortization_installment_id', $installment->id)->count())->toBe(1);
});

it('mueve el residual de 450 de la inicial al cerrar con el closer de 500', function () {
    $contract = residualContract(ContractStatus::PREVENTA_INACTIVA->value, 0, '20000000.00');
    $initial = $contract->amortizationInstallments()->where('installment_number', 0)->firstOrFail();

    app(DownPaymentService::class)->registerDownPayment(new CreateTransactionDTO(
        contractId: $contract->id,
        amount: '19999550.00',
        transactionDate: Carbon::parse('2025-01-06'),
        paymentMethod: PaymentMethod::CASH,
        transactionType: TransactionType::DOWN_PAYMENT,
        installmentNumbers: [],
    ));

    $row = ContractResidualBalance::query()->where('amortization_installment_id', $initial->id)->first();

    expect($initial->fresh()->quota_debt)->toBe('0.00')
        ->and($initial->fresh()->status)->toBe(AmortizationStatus::PAID)
        ->and($row)->not->toBeNull()
        ->and(number_format((float) $row->amount, 2, '.', ''))->toBe('450.00');
});

it('no registra residual de inicial cuando el faltante es 600', function () {
    $contract = residualContract(ContractStatus::PREVENTA_INACTIVA->value, 0, '20000000.00');

    app(DownPaymentService::class)->registerDownPayment(new CreateTransactionDTO(
        contractId: $contract->id,
        amount: '19999400.00',
        transactionDate: Carbon::parse('2025-01-06'),
        paymentMethod: PaymentMethod::CASH,
        transactionType: TransactionType::DOWN_PAYMENT,
        installmentNumbers: [],
    ));

    expect(ContractResidualBalance::query()->count())->toBe(0)
        ->and($contract->amortizationInstallments()->where('installment_number', 0)->value('quota_debt'))->toBe('600.00');
});

it('expone el SUM de residuales al final de la cascada sin escribir de nuevo', function () {
    $contract = residualContract();
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();

    $result = app(CascadeCollectionService::class)->process(
        contractId: $contract->id,
        amount: '998.00',
        selectedInstallmentIds: [(int) $installment->id],
        persistTransaction: false,
    );

    expect($result['pending_residual_balance'])->toBe('2.00')
        ->and($result['residual_balance_collectible'])->toBeFalse()
        ->and(ContractResidualBalance::query()->count())->toBe(1);
});
