<?php

use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Enums\PaymentMethod;
use App\Enums\ResidualBalanceStatus;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\BankAccount;
use App\Models\Contract;
use App\Models\ContractResidualBalance;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Collection\PaymentReversalService;
use App\Services\Financial\Refinancing\RefinanceContractService;
use App\Support\DownPaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function reversalMoney(mixed $value): string
{
    return number_format((float) $value, 2, '.', '');
}

function reversalContract(array $overrides = []): Contract
{
    $suffix = substr(str_replace('.', '', uniqid('', true)), -8);

    $project = Project::query()->create([
        'name' => 'Proyecto Reversa '.$suffix,
        'description' => 'Fixture reversa',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'status' => $overrides['lot_status'] ?? LotStatus::DISPONIBLE,
    ]);

    $contract = Contract::factory()->create(array_merge([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'sale_price' => '5000.00',
        'down_payment_pactada' => '1000.00',
        'term_months' => 3,
        'interest_rate' => 0,
        'status' => $overrides['status'] ?? ContractStatus::ACTIVO,
        'seller_name' => $overrides['seller_name'] ?? 'Vendedor',
        'start_date' => now()->subMonths(3)->toDateString(),
        'initial_payment_date' => now()->subMonths(3)->toDateString(),
        'first_installment_date' => now()->subMonths(2)->toDateString(),
        'regular_payment_start_date' => now()->subMonths(2)->toDateString(),
    ], array_diff_key($overrides, [
        'lot_status' => true,
        'initial_paid' => true,
        'initial_debt' => true,
        'initial_status' => true,
        'seller_name' => true,
        'status' => true,
    ])));

    $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 0,
        'due_date' => now()->subMonths(3)->toDateString(),
        'installment_value' => '1000.00',
        'principal_value' => '1000.00',
        'interest_value' => '0.00',
        'extra_payment' => '0.00',
        'remaining_balance' => '4000.00',
        'projected_balance' => '4000.00',
        'interest_paid' => '0.00',
        'principal_paid' => $overrides['initial_paid'] ?? '0.00',
        'quota_debt' => $overrides['initial_debt'] ?? '1000.00',
        'status' => $overrides['initial_status'] ?? AmortizationStatus::PARTIAL->value,
    ]);

    foreach ([1, 2] as $n) {
        $contract->amortizationInstallments()->create([
            'contract_id' => $contract->id,
            'installment_number' => $n,
            'due_date' => now()->subMonths(3 - $n)->toDateString(),
            'installment_value' => '1000.00',
            'principal_value' => '1000.00',
            'interest_value' => '0.00',
            'extra_payment' => '0.00',
            'remaining_balance' => (string) (4000 - ($n * 1000)),
            'projected_balance' => (string) (4000 - ($n * 1000)),
            'interest_paid' => '0.00',
            'principal_paid' => '0.00',
            'quota_debt' => '1000.00',
            'status' => AmortizationStatus::PENDING->value,
        ]);
    }

    return $contract->fresh(['lot', 'amortizationInstallments']);
}

function reversalResidualContract(): Contract
{
    $suffix = substr(str_replace('.', '', uniqid('', true)), -8);
    $project = Project::query()->create([
        'name' => 'Proyecto Residual Reversa',
        'description' => 'Fixture',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '400'.$suffix,
        'name' => 'Cliente Residual Reversa',
        'phone' => '311'.$suffix,
    ]);
    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'RR-'.$suffix,
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);
    $contract = Contract::create([
        'contract_number' => 'CT-RES-REV-'.$suffix,
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 4000,
        'down_payment_pactada' => '0.00',
        'term_months' => 3,
        'interest_rate' => 0,
        'start_date' => now()->subMonths(3)->toDateString(),
        'initial_payment_date' => now()->subMonths(3)->toDateString(),
        'first_installment_date' => now()->subMonths(2)->toDateString(),
        'regular_payment_start_date' => now()->subMonths(2)->toDateString(),
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    for ($number = 1; $number <= 3; $number++) {
        $contract->amortizationInstallments()->create([
            'contract_id' => $contract->id,
            'installment_number' => $number,
            'due_date' => now()->subMonths(3 - $number)->toDateString(),
            'installment_value' => 1000,
            'principal_value' => 800,
            'interest_value' => 200,
            'extra_payment' => 0,
            'remaining_balance' => 0,
            'projected_balance' => 0,
            'interest_paid' => 200,
            'principal_paid' => 800,
            'quota_debt' => 0,
            'status' => AmortizationStatus::PAID->value,
        ]);
    }

    $installments = $contract->amortizationInstallments()->orderBy('installment_number')->get()->values();
    foreach ($installments as $installment) {
        ContractResidualBalance::query()->create([
            'contract_id' => $contract->id,
            'amortization_installment_id' => $installment->id,
            'amount' => '200.00',
            'status' => ResidualBalanceStatus::PENDIENTE,
        ]);
    }

    return $contract->fresh(['amortizationInstallments']);
}

function reversalSplitContract(): Contract
{
    $project = Project::query()->create([
        'name' => 'Proyecto Split Reversa',
        'description' => 'Fixture',
        'location' => 'Cali',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => '78',
        'list_price' => '100000000.00',
        'status' => LotStatus::PREVENTA,
    ]);

    $contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'contract_number' => 'SPLIT-REV-78',
        'sale_price' => '100000000.00',
        'down_payment_pactada' => '10000000.00',
        'term_months' => 48,
        'interest_rate' => '1.00',
        'status' => ContractStatus::PREVENTA_INACTIVA,
        'is_custom_plan' => false,
        'is_special_lot' => false,
    ]);

    AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 0,
        'due_date' => '2026-01-05',
        'installment_value' => '10000000.00',
        'extra_payment' => '0.00',
        'interest_value' => '0.00',
        'principal_value' => '10000000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '9800000.00',
        'quota_debt' => '200000.00',
        'remaining_balance' => '90000000.00',
        'projected_balance' => '90000000.00',
        'status' => AmortizationStatus::PARTIAL,
    ]);
    AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 1,
        'due_date' => '2026-02-05',
        'installment_value' => '1900000.00',
        'extra_payment' => '0.00',
        'interest_value' => '900000.00',
        'principal_value' => '1000000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1900000.00',
        'remaining_balance' => '89000000.00',
        'projected_balance' => '89000000.00',
        'status' => AmortizationStatus::PENDING,
    ]);

    Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::DOWN_PAYMENT,
        'amount' => '9800000.00',
        'transaction_date' => '2026-01-05',
        'payment_method' => 'transfer',
    ]);

    return $contract->fresh(['lot', 'amortizationInstallments']);
}

function postPaymentReversal(int $contractId, int $transactionId, array $payload = [])
{
    return test()->postJson(
        "/api/contracts/{$contractId}/transactions/{$transactionId}/reversal",
        array_merge(['reason' => 'error_captura'], $payload),
    );
}

function postReversalCascade(int $contractId, string|int $amount, array $extra = [])
{
    return test()->postJson('/api/collections/cascade', array_merge([
        'contract_id' => $contractId,
        'amount' => $amount,
        'payment_method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '0258',
    ], $extra));
}

beforeEach(function () {
    $this->admin = $this->actingAsRole(RoleName::ADMINISTRADOR->value);
    $this->bankAccount = BankAccount::query()->create([
        'bank_name' => 'Bancolombia',
        'account_number' => '0101010199',
        'account_type' => 'savings',
        'holder_name' => 'Constructora QA',
    ]);
});

it('revierte un cobro residual FIFO con parcial y restaura las filas', function () {
    $contract = reversalResidualContract();

    $response = $this->post('/api/collections/residual', [
        'contract_id' => $contract->id,
        'amount' => '500.00',
        'payment_method' => PaymentMethod::TRANSFER->value,
        'bank_account_id' => $this->bankAccount->id,
        'transaction_date' => '2026-09-09',
        'receipt_number' => '0448-0449',
        'receipt' => UploadedFile::fake()->create('recibo.pdf', 20, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $txId = (int) $response->json('data.transaction_id');
    $partial = ContractResidualBalance::query()
        ->where('contract_id', $contract->id)
        ->where('status', ResidualBalanceStatus::PENDIENTE)
        ->first();

    expect($partial)->not->toBeNull()
        ->and(reversalMoney($partial->amount))->toBe('100.00')
        ->and((int) $partial->last_partial_transaction_id)->toBe($txId)
        ->and(reversalMoney($partial->last_partial_amount))->toBe('100.00');

    postPaymentReversal($contract->id, $txId)
        ->assertCreated()
        ->assertJsonPath('data.amount', '500.00');

    $rows = ContractResidualBalance::query()
        ->where('contract_id', $contract->id)
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(3);
    foreach ($rows as $row) {
        expect($row->status)->toBe(ResidualBalanceStatus::PENDIENTE)
            ->and(reversalMoney($row->amount))->toBe('200.00')
            ->and($row->collected_transaction_id)->toBeNull()
            ->and($row->last_partial_transaction_id)->toBeNull();
    }

    $original = Transaction::query()->findOrFail($txId);
    $reversalRow = Transaction::query()
        ->where('contract_id', $contract->id)
        ->where('transaction_type', TransactionType::PAYMENT_REVERSAL)
        ->first();

    expect($original->reversed_at)->not->toBeNull()
        ->and($original->reversal_reason)->toBeNull()
        ->and($reversalRow)->not->toBeNull()
        ->and($reversalRow->reversal_reason)->toBe('error_captura')
        ->and(reversalMoney($reversalRow->amount))->toBe('500.00');
});

it('desactiva el contrato y el lote al revertir la inicial que los activó', function () {
    $contract = reversalContract([
        'lot_status' => LotStatus::PREVENTA,
        'status' => ContractStatus::PREVENTA_INACTIVA,
        'initial_debt' => '1000.00',
        'initial_paid' => '0.00',
        'initial_status' => AmortizationStatus::PARTIAL->value,
    ]);

    postReversalCascade($contract->id, 1000)->assertCreated();

    $contract->refresh();
    expect($contract->status)->toBe(ContractStatus::ACTIVO)
        ->and($contract->lot->fresh()->status)->toBe(LotStatus::VENDIDO)
        ->and(DownPaymentLedger::isSettled($contract))->toBeTrue();

    $tx = $contract->transactions()->where('transaction_type', TransactionType::DOWN_PAYMENT)->firstOrFail();

    postPaymentReversal($contract->id, (int) $tx->id)->assertCreated();

    $contract->refresh();
    $inicial = $contract->amortizationInstallments()->where('installment_number', 0)->firstOrFail();

    expect($contract->status)->toBe(ContractStatus::PREVENTA_INACTIVA)
        ->and($contract->lot->fresh()->status)->toBe(LotStatus::PREVENTA)
        ->and(DownPaymentLedger::isSettled($contract))->toBeFalse()
        ->and(reversalMoney($inicial->quota_debt))->toBe('1000.00')
        ->and($inicial->status)->toBe(AmortizationStatus::PARTIAL);
});

it('revierte una cascada que cubrió varias cuotas', function () {
    $contract = reversalContract([
        'lot_status' => LotStatus::DISPONIBLE,
        'initial_debt' => '1000.00',
    ]);

    postReversalCascade($contract->id, 2000)->assertCreated();

    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    $cuota2 = $contract->amortizationInstallments()->where('installment_number', 2)->firstOrFail();
    expect(reversalMoney($cuota1->fresh()->quota_debt))->toBe('0.00')
        ->and(reversalMoney($cuota2->fresh()->quota_debt))->toBe('0.00');

    $tx = $contract->transactions()->where('transaction_type', TransactionType::REGULAR_PAYMENT)->firstOrFail();
    postPaymentReversal($contract->id, (int) $tx->id)->assertCreated();

    expect(reversalMoney($cuota1->fresh()->quota_debt))->toBe('1000.00')
        ->and(reversalMoney($cuota2->fresh()->quota_debt))->toBe('1000.00')
        ->and($cuota1->fresh()->status)->not->toBe(AmortizationStatus::PAID)
        ->and($cuota2->fresh()->status)->not->toBe(AmortizationStatus::PAID);
});

it('revierte un pago mixto atómico y reabre inicial y cuota', function () {
    $contract = reversalSplitContract();
    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();

    $this->postJson('/api/collections/split', [
        'contract_id' => $contract->id,
        'amount' => 2100000,
        'to_down_payment' => 200000,
        'to_installments' => 1900000,
        'payment_date' => '2026-02-10',
        'payment_method' => 'transfer',
        'bank_account_id' => $this->bankAccount->id,
        'selected_installments' => [$cuota1->id],
        'receipt_number' => '0258',
    ])->assertCreated();

    $contract->refresh();
    expect($contract->status)->toBe(ContractStatus::ACTIVO)
        ->and(DownPaymentLedger::isSettled($contract))->toBeTrue();

    $split = $contract->transactions()->where('transaction_type', TransactionType::SPLIT_PAYMENT)->firstOrFail();
    postPaymentReversal($contract->id, (int) $split->id)->assertCreated();

    $contract->refresh();
    $inicial = $contract->amortizationInstallments()->where('installment_number', 0)->firstOrFail();
    $cuota1->refresh();

    expect($contract->status)->toBe(ContractStatus::PREVENTA_INACTIVA)
        ->and($contract->lot->fresh()->status)->toBe(LotStatus::PREVENTA)
        ->and(DownPaymentLedger::isSettled($contract))->toBeFalse()
        ->and(reversalMoney($inicial->quota_debt))->toBe('200000.00')
        ->and(reversalMoney($cuota1->quota_debt))->toBe('1900000.00');
});

it('el par preventa+cascada se revierte como un solo evento', function () {
    $contract = reversalContract([
        'lot_status' => LotStatus::PREVENTA,
        'status' => ContractStatus::PREVENTA_INACTIVA,
        'initial_debt' => '1000.00',
        'initial_paid' => '0.00',
    ]);

    postReversalCascade($contract->id, 1500, ['receipt_number' => '0258'])->assertCreated();

    $down = $contract->transactions()->where('transaction_type', TransactionType::DOWN_PAYMENT)->firstOrFail();
    $regular = $contract->transactions()->where('transaction_type', TransactionType::REGULAR_PAYMENT)->firstOrFail();

    $response = postPaymentReversal($contract->id, (int) $regular->id)
        ->assertCreated();

    expect($response->json('data.reversed_transaction_ids'))->toEqualCanonicalizing([(int) $down->id, (int) $regular->id]);

    expect($down->fresh()->reversed_at)->not->toBeNull()
        ->and($regular->fresh()->reversed_at)->not->toBeNull()
        ->and($down->fresh()->reversal_transaction_id)->toBe($regular->fresh()->reversal_transaction_id);

    $reversals = Transaction::query()
        ->where('contract_id', $contract->id)
        ->where('transaction_type', TransactionType::PAYMENT_REVERSAL)
        ->get();
    expect($reversals)->toHaveCount(1)
        ->and(reversalMoney($reversals->first()->amount))->toBe('1500.00');

    expect(reversalMoney($contract->amortizationInstallments()->where('installment_number', 0)->first()->quota_debt))->toBe('1000.00')
        ->and(reversalMoney($contract->amortizationInstallments()->where('installment_number', 1)->first()->quota_debt))->toBe('1000.00');
});

it('responde 422 si se intenta revertir un cobro que no es el último', function () {
    $contract = reversalContract(['lot_status' => LotStatus::DISPONIBLE]);

    postReversalCascade($contract->id, 1000, ['receipt_number' => '0101', 'transaction_date' => now()->subDay()->toDateString()])
        ->assertCreated();
    postReversalCascade($contract->id, 1000, ['receipt_number' => '0102'])->assertCreated();

    $first = $contract->transactions()->orderBy('id')->firstOrFail();
    $last = $contract->transactions()->orderByDesc('id')->firstOrFail();

    postPaymentReversal($contract->id, (int) $first->id)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['transaction'])
        ->assertJsonPath('errors.transaction.0', PaymentReversalService::NOT_LAST);

    postPaymentReversal($contract->id, (int) $last->id)->assertCreated();
});

it('responde 422 si hay una refinanciación posterior al cobro', function () {
    $contract = reversalContract(['lot_status' => LotStatus::DISPONIBLE]);
    postReversalCascade($contract->id, 1000)->assertCreated();
    $tx = $contract->transactions()->latest('id')->firstOrFail();

    $log = activity(RefinanceContractService::LOG_NAME)
        ->performedOn($contract)
        ->event('updated')
        ->log('Refinanció el contrato');
    $log->forceFill([
        'created_at' => now()->addMinute(),
        'updated_at' => now()->addMinute(),
    ])->save();

    postPaymentReversal($contract->id, (int) $tx->id)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['transaction'])
        ->assertJsonPath('errors.transaction.0', PaymentReversalService::LATER_REFINANCE);
});

it('responde 422 si el cobro recálculó el plan con reducir_plazo', function () {
    $contract = reversalContract(['lot_status' => LotStatus::DISPONIBLE]);
    postReversalCascade($contract->id, 1000, ['payment_option' => 'reducir_plazo'])->assertCreated();
    $tx = $contract->transactions()->latest('id')->firstOrFail();

    expect($tx->payment_option)->toBe('reducir_plazo');

    postPaymentReversal($contract->id, (int) $tx->id)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['transaction'])
        ->assertJsonPath('errors.transaction.0', PaymentReversalService::PLAN_REWRITE);
});

it('responde 422 si el cobro no tiene allocations', function () {
    $contract = reversalContract(['lot_status' => LotStatus::DISPONIBLE]);
    $tx = Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::REGULAR_PAYMENT,
        'amount' => '1000.00',
        'transaction_date' => now()->toDateString(),
        'payment_method' => 'cash',
        'receipt_number' => '0999',
    ]);

    postPaymentReversal($contract->id, (int) $tx->id)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['transaction'])
        ->assertJsonPath('errors.transaction.0', PaymentReversalService::NO_ALLOCATIONS);
});

it('responde 403 si el socio intenta revertir', function () {
    $contract = reversalContract(['lot_status' => LotStatus::DISPONIBLE]);
    postReversalCascade($contract->id, 1000)->assertCreated();
    $tx = $contract->transactions()->latest('id')->firstOrFail();

    $this->actingAsRole(RoleName::SOCIO_GERENCIA->value, User::factory()->create());

    postPaymentReversal($contract->id, (int) $tx->id)->assertForbidden();
});

it('responde 201 cuando el administrador revierte el último cobro', function () {
    $contract = reversalContract(['lot_status' => LotStatus::DISPONIBLE]);
    postReversalCascade($contract->id, 1000)->assertCreated();
    $tx = $contract->transactions()->latest('id')->firstOrFail();

    $history = $this->getJson("/api/contracts/{$contract->id}/transactions")->assertOk();
    $row = collect($history->json('data.data'))->firstWhere('id', $tx->id);
    expect($row['can_reverse'])->toBeTrue();

    postPaymentReversal($contract->id, (int) $tx->id, [
        'reason' => 'otro',
        'notes' => 'Se capturó el recibo del cliente equivocado.',
    ])->assertCreated()
        ->assertJsonPath('data.reversed_transaction_ids.0', $tx->id);

    $reversal = Transaction::query()
        ->where('contract_id', $contract->id)
        ->where('transaction_type', TransactionType::PAYMENT_REVERSAL)
        ->firstOrFail();

    expect($reversal->reversal_reason)->toBe('otro')
        ->and($reversal->reversal_notes)->toBe('Se capturó el recibo del cliente equivocado.')
        ->and($tx->fresh()->reversal_reason)->toBeNull();
});
