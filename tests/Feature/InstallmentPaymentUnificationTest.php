<?php

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Financial\Transaction\InstallmentPaymentAllocator;
use App\Services\Financial\Transaction\RegularPayment\RegularPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function unificationContract(string $status = 'activo', int $regularCount = 1): Contract
{
    $project = Project::create([
        'name' => 'Proyecto Unificacion Allocator',
        'description' => 'Fixture',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '1000000888',
        'name' => 'Cliente Unificacion',
        'phone' => '3000000888',
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'U-101',
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => $status === 'preventa_inactiva' ? 'preventa' : 'disponible',
        'type' => 'residential',
    ]);

    $contract = Contract::create([
        'contract_number' => 'CT-UNIF-ALLOC-001',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 4000,
        'down_payment_pactada' => $status === 'preventa_inactiva' ? 2000 : 0,
        'term_months' => max(1, $regularCount),
        'interest_rate' => 0,
        'start_date' => now()->subMonths(3)->toDateString(),
        'initial_payment_date' => now()->subMonths(3)->toDateString(),
        'first_installment_date' => now()->subMonths(2)->toDateString(),
        'regular_payment_start_date' => now()->subMonths(2)->toDateString(),
        'preventa_installments_count' => 0,
        'status' => $status,
    ]);

    if ($status === 'preventa_inactiva') {
        $contract->amortizationInstallments()->create([
            'contract_id' => $contract->id,
            'installment_number' => 0,
            'due_date' => now()->subMonths(3)->toDateString(),
            'installment_value' => 2000,
            'principal_value' => 2000,
            'interest_value' => 0,
            'extra_payment' => 0,
            'remaining_balance' => 2000,
            'projected_balance' => 2000,
            'interest_paid' => 0,
            'principal_paid' => 0,
            'quota_debt' => 2000,
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

it('acumula dos abonos parciales sucesivos sobre la misma cuota sin resetear quota_debt', function () {
    $contract = unificationContract();
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->first();
    $service = app(RegularPaymentService::class);

    $service->registerRegularPayment(new CreateTransactionDTO(
        contractId: $contract->id,
        amount: '300.00',
        transactionDate: Carbon::parse(now()->toDateString()),
        paymentMethod: PaymentMethod::CASH,
        transactionType: TransactionType::REGULAR_PAYMENT,
        installmentNumbers: [(int) $installment->id],
    ));

    $installment->refresh();
    expect($installment->quota_debt)->toBe('700.00')
        ->and($installment->status)->toBe(AmortizationStatus::PARTIAL)
        ->and(number_format((float) $installment->interest_paid, 2, '.', ''))->toBe('200.00')
        ->and(number_format((float) $installment->principal_paid, 2, '.', ''))->toBe('100.00');

    $service->registerRegularPayment(new CreateTransactionDTO(
        contractId: $contract->id,
        amount: '200.00',
        transactionDate: Carbon::parse(now()->toDateString()),
        paymentMethod: PaymentMethod::CASH,
        transactionType: TransactionType::REGULAR_PAYMENT,
        installmentNumbers: [(int) $installment->id],
    ));

    $installment->refresh();
    expect($installment->quota_debt)->toBe('500.00')
        ->and($installment->status)->toBe(AmortizationStatus::PARTIAL)
        ->and(number_format((float) $installment->interest_paid, 2, '.', ''))->toBe('200.00')
        ->and(number_format((float) $installment->principal_paid, 2, '.', ''))->toBe('300.00');
});

it('condona un residual menor a 500 al imputar en cascada', function () {
    $contract = unificationContract();
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->first();

    $result = app(InstallmentPaymentAllocator::class)->applyToInstallment(
        $installment,
        '501.00',
        Carbon::parse(now()->toDateString()),
        $contract,
    );

    $installment->refresh();

    expect($result['quota_debt'])->toBe('0.00')
        ->and($result['status'])->toBe(AmortizationStatus::PAID->value)
        ->and($installment->quota_debt)->toBe('0.00')
        ->and($installment->status)->toBe(AmortizationStatus::PAID)
        ->and(number_format((float) $installment->interest_paid, 2, '.', ''))->toBe('200.00')
        ->and(number_format((float) $installment->principal_paid, 2, '.', ''))->toBe('800.00');
});

it('en preventa con inicial incompleta el FIFO de mora de regulares queda vacío', function () {
    $contract = unificationContract(ContractStatus::PREVENTA_INACTIVA->value, 2);
    $contract->amortizationInstallments()
        ->where('installment_number', '>', 0)
        ->update([
            'due_date' => now()->subMonth()->toDateString(),
            'status' => AmortizationStatus::OVERDUE->value,
        ]);

    $overdue = app(InstallmentPaymentAllocator::class)->unpaidOverdueInstallments($contract->fresh());

    expect($overdue)->toHaveCount(0);
});

it('en preventa con inicial ya dentro de tolerancia si cuenta regulares vencidas', function () {
    $contract = unificationContract(ContractStatus::PREVENTA_INACTIVA->value, 2);
    $contract->amortizationInstallments()->where('installment_number', 0)->update([
        'quota_debt' => '0.00',
        'status' => AmortizationStatus::PAID->value,
        'interest_paid' => '0.00',
        'principal_paid' => '2000.00',
    ]);
    $contract->amortizationInstallments()
        ->where('installment_number', '>', 0)
        ->update([
            'due_date' => now()->subMonth()->toDateString(),
            'status' => AmortizationStatus::OVERDUE->value,
        ]);

    $overdue = app(InstallmentPaymentAllocator::class)->unpaidOverdueInstallments($contract->fresh());

    expect($overdue)->toHaveCount(2);
});

it('no mete en el FIFO de mora una cuota que vence hoy', function () {
    $contract = unificationContract('activo', 2);
    $contract->amortizationInstallments()->where('installment_number', 1)->update([
        'due_date' => now()->subDay()->toDateString(),
        'status' => AmortizationStatus::PENDING->value,
    ]);
    $contract->amortizationInstallments()->where('installment_number', 2)->update([
        'due_date' => now()->toDateString(),
        'status' => AmortizationStatus::PENDING->value,
    ]);

    $allocator = app(InstallmentPaymentAllocator::class);
    $overdue = $allocator->unpaidOverdueInstallments($contract->fresh());

    expect($overdue)->toHaveCount(1)
        ->and((int) $overdue->first()->installment_number)->toBe(1)
        ->and($allocator->resolvePartialStatus(
            $contract->amortizationInstallments()->where('installment_number', 2)->first(),
            $contract,
        ))->toBe(AmortizationStatus::PARTIAL);
});
