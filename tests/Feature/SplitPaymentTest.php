<?php

use App\Enums\AllocationTarget;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\BankAccount;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DownPaymentLedger;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * El caso de la reunión: la cuota inicial está en mora y el cliente manda un
 * solo pago que cubre el faltante de la inicial más la cuota del mes.
 */
function splitContract(array $overrides = []): Contract
{
    $project = Project::query()->create([
        'name' => 'Proyecto Split',
        'description' => 'Fixture pago dividido',
        'location' => 'Cali',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => '77',
        'list_price' => '100000000.00',
        'status' => LotStatus::PREVENTA,
    ]);

    $contract = Contract::factory()->create(array_merge([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'contract_number' => 'SPLIT-77',
        'sale_price' => '100000000.00',
        'down_payment_pactada' => '10000000.00',
        'term_months' => 48,
        'interest_rate' => '1.00',
        'status' => ContractStatus::PREVENTA_INACTIVA,
        'is_custom_plan' => false,
        'is_special_lot' => false,
    ], $overrides));

    // Cuota inicial con $200.000 pendientes y la cuota 1 abierta.
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

    // Lo ya recaudado de la inicial, como transacción normal.
    Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::DOWN_PAYMENT,
        'amount' => '9800000.00',
        'transaction_date' => '2026-01-05',
        'payment_method' => 'transfer',
    ]);

    return $contract->fresh();
}

beforeEach(function () {
    User::factory()->create();
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);
    $this->bankAccount = BankAccount::query()->create([
        'bank_name' => 'Bancolombia',
        'account_number' => '0101010101',
        'account_type' => 'savings',
        'holder_name' => 'Constructora QA',
    ]);
});

it('registra un solo movimiento por el total y guarda el reparto', function () {
    $contract = splitContract();
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

    $transactions = Transaction::query()
        ->where('contract_id', $contract->id)
        ->where('transaction_type', TransactionType::SPLIT_PAYMENT)
        ->get();

    // Lo que ve el banco: una sola fila por $2.100.000.
    expect($transactions)->toHaveCount(1)
        ->and((float) $transactions->first()->amount)->toBe(2100000.0);

    $allocations = $transactions->first()->allocations;
    expect($allocations)->toHaveCount(2)
        ->and((float) $allocations->sum('amount'))->toBe(2100000.0);

    $inicial = $allocations->firstWhere('target', AllocationTarget::DOWN_PAYMENT);
    $regular = $allocations->firstWhere('target', AllocationTarget::INSTALLMENT);

    expect((float) $inicial->amount)->toBe(200000.0)
        ->and((float) $inicial->principal)->toBe(200000.0)
        ->and((float) $regular->amount)->toBe(1900000.0)
        // La cuota regular sí desglosa interés y capital.
        ->and((float) $regular->interest)->toBe(900000.0)
        ->and((float) $regular->principal)->toBe(1000000.0);
});

it('salda la inicial y activa el contrato aunque el pago haya venido repartido', function () {
    $contract = splitContract();
    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();

    $this->postJson('/api/collections/split', [
        'contract_id' => $contract->id,
        'amount' => 2100000,
        'to_down_payment' => 200000,
        'to_installments' => 1900000,
        'payment_date' => '2026-02-10',        'payment_method' => 'transfer',
        'bank_account_id' => $this->bankAccount->id,        'selected_installments' => [$cuota1->id],
        'receipt_number' => '0258',
    ])->assertCreated();

    $contract->refresh();

    // El ledger tiene que contar el reparto: si solo mirara las transacciones
    // de tipo down_payment, la inicial seguiría con $200.000 abiertos.
    expect((float) DownPaymentLedger::collected($contract))->toBe(10000000.0)
        ->and((float) DownPaymentLedger::pending($contract))->toBe(0.0)
        ->and(DownPaymentLedger::isSettled($contract))->toBeTrue()
        ->and($contract->status)->toBe(ContractStatus::ACTIVO)
        ->and($contract->lot->fresh()->status)->toBe(LotStatus::VENDIDO);

    $inicial = $contract->amortizationInstallments()->where('installment_number', 0)->firstOrFail();
    $cuota1->refresh();

    expect($inicial->status)->toBe(AmortizationStatus::PAID)
        ->and((float) $inicial->quota_debt)->toBe(0.0)
        ->and($cuota1->status)->toBe(AmortizationStatus::PAID)
        ->and((float) $cuota1->quota_debt)->toBe(0.0);
});

it('rechaza el pago si el reparto no suma el total recibido', function () {
    $contract = splitContract();

    $this->postJson('/api/collections/split', [
        'contract_id' => $contract->id,
        'amount' => 2100000,
        'to_down_payment' => 200000,
        // Faltan $100.000: la conciliación bancaria no cuadraría.
        'to_installments' => 1800000,
        'payment_date' => '2026-02-10',
        'receipt_number' => '0258',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);

    expect(Transaction::query()->where('transaction_type', TransactionType::SPLIT_PAYMENT)->count())->toBe(0);
});

it('rechaza que la parte de la inicial supere su saldo pendiente', function () {
    $contract = splitContract();

    $this->postJson('/api/collections/split', [
        'contract_id' => $contract->id,
        'amount' => 2100000,
        // Solo quedan $200.000 de inicial.
        'to_down_payment' => 500000,
        'to_installments' => 1600000,
        'payment_date' => '2026-02-10',
        'receipt_number' => '0258',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to_down_payment']);

    expect(Transaction::query()->where('transaction_type', TransactionType::SPLIT_PAYMENT)->count())->toBe(0);
});

it('rechaza dividir cuando la inicial ya está saldada', function () {
    $contract = splitContract();
    $contract->amortizationInstallments()
        ->where('installment_number', 0)
        ->update(['principal_paid' => '10000000.00', 'quota_debt' => '0.00']);
    Transaction::query()
        ->where('contract_id', $contract->id)
        ->where('transaction_type', TransactionType::DOWN_PAYMENT)
        ->update(['amount' => '10000000.00']);

    $this->postJson('/api/collections/split', [
        'contract_id' => $contract->id,
        'amount' => 2100000,
        'to_down_payment' => 200000,
        'to_installments' => 1900000,
        'payment_date' => '2026-02-10',
        'receipt_number' => '0258',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to_down_payment']);
});

it('no deja rastro si la imputación de las cuotas falla', function () {
    $contract = splitContract();
    // Sin cuotas regulares abiertas, la cascada rechaza el pago.
    $contract->amortizationInstallments()
        ->where('installment_number', 1)
        ->update(['status' => AmortizationStatus::PAID->value, 'quota_debt' => '0.00']);

    $this->postJson('/api/collections/split', [
        'contract_id' => $contract->id,
        'amount' => 2100000,
        'to_down_payment' => 200000,
        'to_installments' => 1900000,
        'payment_date' => '2026-02-10',
    ])->assertStatus(422);

    // La transacción y su reparto se deshacen juntos.
    expect(Transaction::query()->where('transaction_type', TransactionType::SPLIT_PAYMENT)->count())->toBe(0)
        ->and(DB::table('transaction_allocations')->count())->toBe(0)
        ->and((float) DownPaymentLedger::pending($contract->fresh()))->toBe(200000.0);
});

it('expone el reparto en el listado de transacciones del contrato', function () {
    $contract = splitContract();
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

    $response = $this->getJson("/api/contracts/{$contract->id}/transactions")->assertOk();
    $split = collect($response->json('data.data'))
        ->firstWhere('transaction_type', TransactionType::SPLIT_PAYMENT->value);

    expect($split)->not->toBeNull()
        ->and((float) $split['amount'])->toBe(2100000.0)
        ->and($split['allocations'])->toHaveCount(2)
        ->and($split['allocations'][0]['target_label'])->toBe('Cuota inicial')
        ->and($split['allocations'][1]['target_label'])->toBe('Cuota regular')
        ->and($split['allocations'][1]['installment_number'])->toBe(1);
});

it('rechaza el pago dividido si la parte regular supera la cuota y no hay destino del excedente', function () {
    $contract = splitContract();
    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    $cuota1->update([
        'installment_value' => '1715402.83',
        'interest_value' => '771160.00',
        'principal_value' => '944242.83',
        'quota_debt' => '1715402.83',
    ]);

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
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payment_option'])
        ->assertJsonPath('errors.payment_option.0', \App\Services\Collection\CascadeCollectionService::SURPLUS_ACTION_REQUIRED);

    expect(Transaction::query()->where('transaction_type', TransactionType::SPLIT_PAYMENT)->count())->toBe(0);
});

it('cuando la parte regular supera la cuota, el extra sale como abono a capital', function () {
    $contract = splitContract();
    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    $cuota1->update([
        'installment_value' => '1715402.83',
        'interest_value' => '771160.00',
        'principal_value' => '944242.83',
        'quota_debt' => '1715402.83',
    ]);

    $this->postJson('/api/collections/split', [
        'contract_id' => $contract->id,
        'amount' => 2100000,
        'to_down_payment' => 200000,
        'to_installments' => 1900000,
        'payment_date' => '2026-02-10',
        'payment_method' => 'transfer',
        'bank_account_id' => $this->bankAccount->id,
        'selected_installments' => [$cuota1->id],
        'payment_option' => 'reducir_plazo',
        'receipt_number' => '0258',
    ])->assertCreated();

    $split = Transaction::query()
        ->where('contract_id', $contract->id)
        ->where('transaction_type', TransactionType::SPLIT_PAYMENT)
        ->firstOrFail();

    $allocations = $split->allocations->sortBy('id')->values();
    $inicial = $allocations->firstWhere('target', AllocationTarget::DOWN_PAYMENT);
    $regular = $allocations->firstWhere('target', AllocationTarget::INSTALLMENT);
    $capital = $allocations->firstWhere('target', AllocationTarget::CAPITAL);

    expect($allocations)->toHaveCount(3)
        ->and((float) $allocations->sum('amount'))->toBe(2100000.0)
        ->and((float) $inicial->amount)->toBe(200000.0)
        ->and((float) $regular->amount)->toBe(1715402.83)
        ->and((float) $regular->principal)->toBe(944242.83)
        ->and((float) $regular->interest)->toBe(771160.0)
        ->and((float) $capital->amount)->toBe(184597.17)
        ->and((float) $capital->principal)->toBe(184597.17)
        ->and((float) $capital->interest)->toBe(0.0)
        ->and($capital->amortization_installment_id)->toBeNull();

    $cuota1->refresh();
    expect((float) $cuota1->extra_payment)->toBe(184597.17);
});

it('la hoja de vida muestra el pago como una sola fila con su desglose', function () {
    $contract = splitContract();
    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();

    $this->postJson('/api/collections/split', [
        'contract_id' => $contract->id,
        'amount' => 2100000,
        'to_down_payment' => 200000,
        'to_installments' => 1900000,
        'payment_date' => '2026-02-10',
        'selected_installments' => [$cuota1->id],
        'receipt_number' => '0258',
    ])->assertCreated();

    $rows = $this->getJson("/api/contracts/{$contract->id}/life-sheet")
        ->assertOk()
        ->json('data.rows');

    $split = collect($rows)->firstWhere('concept', 'CUOTA INICIAL + CUOTA 1');

    expect($split)->not->toBeNull()
        ->and((float) $split['amount'])->toBe(2100000.0)
        ->and($split['allocations'])->toHaveCount(2);

    // Un solo movimiento en la hoja de vida: dos filas descuadrarían el saldo.
    expect(collect($rows)->where('amount', '2100000.00'))->toHaveCount(1);
});

it('guarda Recibo # en el movimiento dividido', function () {
    $contract = splitContract();
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
        'receipt_number' => '0258, 0289',
    ])->assertCreated();

    $tx = Transaction::query()
        ->where('contract_id', $contract->id)
        ->where('transaction_type', TransactionType::SPLIT_PAYMENT)
        ->first();

    expect($tx->receipt_number)->toBe('0258-0289')
        ->and($tx->notes)->toBe('Recibo #0258-0289');
});

it('reparte #0 + dos regulares cuando el monto cubre la inicial y solo parte de las cuotas', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));

    $contract = splitContract([
        'status' => ContractStatus::ACTIVO,
        'down_payment_pactada' => '1000.00',
        'sale_price' => '3000.00',
        'interest_rate' => '0.00',
        'term_months' => 2,
    ]);
    $contract->lot->update(['status' => LotStatus::VENDIDO]);
    $contract->transactions()->delete();
    $contract->amortizationInstallments()->delete();

    $inicial = AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 0,
        'due_date' => '2026-01-10',
        'installment_value' => '1000.00',
        'extra_payment' => '0.00',
        'interest_value' => '0.00',
        'principal_value' => '1000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000.00',
        'remaining_balance' => '2000.00',
        'projected_balance' => '2000.00',
        'status' => AmortizationStatus::PENDING,
    ]);
    $cuota1 = AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 1,
        'due_date' => '2026-08-10',
        'installment_value' => '1000.00',
        'extra_payment' => '0.00',
        'interest_value' => '0.00',
        'principal_value' => '1000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000.00',
        'remaining_balance' => '2000.00',
        'projected_balance' => '2000.00',
        'status' => AmortizationStatus::PENDING,
    ]);
    $cuota2 = AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => 2,
        'due_date' => '2026-10-10',
        'installment_value' => '1000.00',
        'extra_payment' => '0.00',
        'interest_value' => '0.00',
        'principal_value' => '1000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000.00',
        'remaining_balance' => '1000.00',
        'projected_balance' => '1000.00',
        'status' => AmortizationStatus::PENDING,
    ]);

    $this->postJson('/api/collections/split', [
        'contract_id' => $contract->id,
        'amount' => 1500,
        'to_down_payment' => 1000,
        'to_installments' => 500,
        'payment_date' => '2026-09-10',
        'payment_method' => 'transfer',
        'bank_account_id' => $this->bankAccount->id,
        'selected_installments' => [$inicial->id, $cuota1->id, $cuota2->id],
        'receipt_number' => '0420',
    ])->assertCreated();

    $tx = Transaction::query()
        ->where('contract_id', $contract->id)
        ->where('transaction_type', TransactionType::SPLIT_PAYMENT)
        ->firstOrFail();

    expect((float) $tx->amount)->toBe(1500.0)
        ->and($contract->transactions()->count())->toBe(1);

    $inicial->refresh();
    $cuota1->refresh();
    $cuota2->refresh();

    expect($inicial->status)->toBe(AmortizationStatus::PAID)
        ->and((float) $inicial->quota_debt)->toBe(0.0)
        ->and((float) $cuota1->quota_debt)->toBe(500.0)
        ->and($cuota1->status)->not->toBe(AmortizationStatus::PAID)
        ->and($cuota2->status)->toBe(AmortizationStatus::PENDING)
        ->and((float) $cuota2->quota_debt)->toBe(1000.0);

    $down = $tx->allocations->firstWhere('target', AllocationTarget::DOWN_PAYMENT);
    $regulars = $tx->allocations->where('target', AllocationTarget::INSTALLMENT);

    expect((float) $down->amount)->toBe(1000.0)
        ->and((float) $regulars->sum('amount'))->toBe(500.0);

    Carbon::setTestNow();
});
