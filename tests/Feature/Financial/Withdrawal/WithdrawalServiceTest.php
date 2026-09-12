<?php

use App\Enums\TransactionType;
use App\Enums\WithdrawalCause;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Services\Financial\Withdrawal\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Enums\WithdrawalStatus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new WithdrawalService();
});

it('calcula correctamente un desistimiento por retracto de ley', function () {
    $contract = createWithdrawalTestContract();

    createWithdrawalTestTransaction(
        $contract,
        TransactionType::DOWN_PAYMENT->value,
        5_000_000,
        '2026-09-01'
    );

    createWithdrawalTestTransaction(
        $contract,
        TransactionType::DOWN_PAYMENT->value,
        3_000_000,
        '2026-09-05'
    );

    $result = $this->service->calculatePreventa(
        $contract,
        WithdrawalCause::RETRACTO_DE_LEY,
        Carbon::parse('2026-09-05')
    );

    expect($result['contributions'])->toBe(8_000_000.0)
        ->and($result['authorized_retention_percentage'])->toBe(0.0)
        ->and($result['penalty_amount'])->toBe(0.0)
        ->and($result['refund_balance'])->toBe(8_000_000.0);
});

it('solo considera aportes realizados hasta la fecha del desistimiento', function () {
    $contract = createWithdrawalTestContract(
        salePrice: 100_000_000
    );

    createWithdrawalTestTransaction(
        $contract,
        TransactionType::DOWN_PAYMENT->value,
        5_000_000,
        '2026-09-05'
    );

    createWithdrawalTestTransaction(
        $contract,
        TransactionType::DOWN_PAYMENT->value,
        2_000_000,
        '2026-09-10'
    );

    $result = $this->service->calculatePreventa(
        $contract,
        WithdrawalCause::RETRACTO_DE_LEY,
        Carbon::parse('2026-09-07')
    );

    expect($result['contributions'])->toBe(5_000_000.0);
});

it('no considera pagos que no sean de cuota inicial', function () {
    $contract = createWithdrawalTestContract(
        salePrice: 100_000_000
    );

    createWithdrawalTestTransaction(
        $contract,
        TransactionType::DOWN_PAYMENT->value,
        5_000_000,
        '2026-09-05'
    );

    createWithdrawalTestTransaction(
        $contract,
        TransactionType::REGULAR_PAYMENT->value,
        10_000_000,
        '2026-09-05'
    );

    $result = $this->service->calculatePreventa(
        $contract,
        WithdrawalCause::RETRACTO_DE_LEY,
        Carbon::parse('2026-09-05')
    );

    expect($result['contributions'])->toBe(5_000_000.0);
});

it('calcula la multa de fuerza mayor sobre los aportes realizados', function () {
    $contract = createWithdrawalTestContract(
        salePrice: 100_000_000
    );

    createWithdrawalTestTransaction(
        $contract,
        TransactionType::DOWN_PAYMENT->value,
        20_000_000,
        '2026-09-05'
    );

    $result = $this->service->calculatePreventa(
        $contract,
        WithdrawalCause::FUERZA_MAYOR,
        Carbon::parse('2026-09-05'),
        25
    );

    expect($result['sale_price'])->toBe(100_000_000.0)
        ->and($result['contributions'])->toBe(20_000_000.0)
        ->and($result['authorized_retention_percentage'])->toBe(25.0)
        ->and($result['penalty_amount'])->toBe(5_000_000.0)
        ->and($result['refund_balance'])->toBe(15_000_000.0);
});

it('usa 10 por ciento por defecto para desistimiento voluntario', function () {
    $contract = createWithdrawalTestContract(
        salePrice: 100_000_000
    );

    createWithdrawalTestTransaction(
        $contract,
        TransactionType::DOWN_PAYMENT->value,
        30_000_000,
        '2026-09-05'
    );

    $result = $this->service->calculatePreventa(
        $contract,
        WithdrawalCause::VOLUNTARIO,
        Carbon::parse('2026-09-05')
    );

    expect($result['authorized_retention_percentage'])->toBe(10.0)
        ->and($result['penalty_amount'])->toBe(3_000_000.0)
        ->and($result['refund_balance'])->toBe(27_000_000.0);
});

it('permite modificar el porcentaje voluntario entre 10 y 100 por ciento', function () {
    $contract = createWithdrawalTestContract(
        salePrice: 100_000_000
    );

    createWithdrawalTestTransaction(
        $contract,
        TransactionType::DOWN_PAYMENT->value,
        30_000_000,
        '2026-09-05'
    );

    $result = $this->service->calculatePreventa(
        $contract,
        WithdrawalCause::VOLUNTARIO,
        Carbon::parse('2026-09-05'),
        40
    );

    expect($result['authorized_retention_percentage'])->toBe(40.0)
        ->and($result['penalty_amount'])->toBe(12_000_000.0)
        ->and($result['refund_balance'])->toBe(18_000_000.0);
});

it('rechaza un porcentaje voluntario menor al 10 por ciento', function () {
    $contract = createWithdrawalTestContract(
        salePrice: 100_000_000
    );

    expect(fn () => $this->service->calculatePreventa(
        $contract,
        WithdrawalCause::VOLUNTARIO,
        Carbon::parse('2026-09-05'),
        5
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('establece la devolución en cero cuando la retención es del 100 por ciento', function () {
    $contract = createWithdrawalTestContract(
        '100-percent',
        100_000_000
    );

    createWithdrawalTestTransaction(
        $contract,
        TransactionType::DOWN_PAYMENT->value,
        5_000_000,
        '2026-09-01'
    );

    $result = $this->service->calculatePreventa(
        $contract,
        WithdrawalCause::FUERZA_MAYOR,
        Carbon::parse('2026-09-05'),
        100
    );

    expect($result['contributions'])->toBe(5_000_000.0)
        ->and($result['authorized_retention_percentage'])->toBe(100.0)
        ->and($result['penalty_amount'])->toBe(5_000_000.0)
        ->and($result['refund_balance'])->toBe(0.0);
});

it('solo permite desistimiento sobre contratos en preventa inactiva', function () {
    $contract = createWithdrawalTestContract();

    $contract->update([
        'status' => 'activo',
    ]);

    expect(fn () => $this->service->calculatePreventa(
        $contract,
        WithdrawalCause::RETRACTO_DE_LEY,
        Carbon::parse('2026-09-05')
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});

function createWithdrawalTestContract(
    string $suffix = '001',
    float $salePrice = 100_000_000
): Contract {
    $project = Project::create([
        'name' => 'Proyecto Withdrawal '.$suffix,
        'description' => 'Proyecto de prueba',
        'location' => 'Cali',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '9'.$suffix,
        'name' => 'Cliente Withdrawal '.$suffix,
        'phone' => '300'.$suffix,
    ]);

    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'WD-'.$suffix,
        'area_m2' => 100,
        'price_m2' => 1_000_000,
        'list_price' => $salePrice,
        'status' => 'disponible',
        'type' => 'residential',
    ]);

    return Contract::create([
        'contract_number' => 'CT-WD-'.$suffix,
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => $salePrice,
        'down_payment_pactada' => 30_000_000,
        'term_months' => 12,
        'interest_rate' => 0,
        'start_date' => '2026-09-01',
        'initial_payment_date' => '2026-09-01',
        'first_installment_date' => '2026-10-01',
        'regular_payment_start_date' => '2026-10-01',
        'preventa_installments_count' => 3,
        'status' => 'preventa_inactiva',
    ]);
}

function createWithdrawalTestTransaction(
    Contract $contract,
    string $transactionType,
    float $amount,
    string $transactionDate = '2026-09-05'
): Transaction {
    return Transaction::create([
        'contract_id' => $contract->id,
        'transaction_type' => $transactionType,
        'amount' => $amount,
        'transaction_date' => $transactionDate,
        'payment_method' => 'transfer',
    ]);
}

it('registra un desistimiento en preventa y actualiza contrato y lote', function () {
    $contract = createWithdrawalTestContract('011');

    createWithdrawalTestTransaction(
        $contract,
        TransactionType::DOWN_PAYMENT->value,
        20_000_000,
        '2026-09-05'
    );

    $service = app(WithdrawalService::class);

    $withdrawal = $service->createPreventa(
        $contract,
        WithdrawalCause::RETRACTO_DE_LEY,
        Carbon::parse('2026-09-10')
    );

    expect($withdrawal->exists)->toBeTrue()
        ->and($withdrawal->refund_balance)->toBe('20000000.00')
        ->and($withdrawal->status)->toBe(WithdrawalStatus::PENDING);

    $contract->refresh();
    $contract->load('lot');

    expect($contract->status)->toBe(ContractStatus::RESCINDIDO)
        ->and($contract->lot->status)->toBe(LotStatus::DISPONIBLE);
});

it('completa inmediatamente un desistimiento sin saldo por devolver', function () {
    $contract = createWithdrawalTestContract('012');

    $service = app(WithdrawalService::class);

    $withdrawal = $service->createPreventa(
        $contract,
        WithdrawalCause::VOLUNTARIO,
        Carbon::parse('2026-09-10'),
        100
    );

    expect($withdrawal->exists)->toBeTrue()
        ->and($withdrawal->refund_balance)->toBe('0.00')
        ->and($withdrawal->status)->toBe(WithdrawalStatus::COMPLETED);

    $contract->refresh();
    $contract->load('lot');

    expect($contract->status)->toBe(ContractStatus::RESCINDIDO)
        ->and($contract->lot->status)->toBe(LotStatus::DISPONIBLE);
});

it('no permite registrar dos desistimientos para el mismo contrato', function () {
    $contract = createWithdrawalTestContract('013');

    $service = app(WithdrawalService::class);

    $service->createPreventa(
        $contract,
        WithdrawalCause::RETRACTO_DE_LEY,
        Carbon::parse('2026-09-10')
    );

    expect(fn () => $service->createPreventa(
        $contract,
        WithdrawalCause::RETRACTO_DE_LEY,
        Carbon::parse('2026-09-11')
    ))->toThrow(
        \Illuminate\Validation\ValidationException::class,
        'El contrato no se encuentra en estado de preventa inactiva.'
    );
});