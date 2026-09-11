<?php

use App\Enums\AmortizationStatus;
use App\Enums\LotStatus;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\BankAccount;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Services\Collection\CascadeCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function preventaCascadeContract(string $lotStatus, string $pactada, string $initialDebt): Contract
{
    $project = Project::query()->create([
        'name' => 'Proyecto Preventa Cascade',
        'description' => 'Fixture',
        'location' => 'Bogota',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'status' => $lotStatus,
    ]);

    $contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'sale_price' => '5000.00',
        'down_payment_pactada' => $pactada,
        'term_months' => 3,
        'interest_rate' => 0,
        'status' => $lotStatus === LotStatus::PREVENTA->value ? 'preventa_inactiva' : 'activo',
        'start_date' => now()->subMonths(3)->toDateString(),
        'initial_payment_date' => now()->subMonths(3)->toDateString(),
        'first_installment_date' => now()->subMonths(2)->toDateString(),
        'regular_payment_start_date' => now()->subMonths(2)->toDateString(),
    ]);

    $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 0,
        'due_date' => now()->subMonths(3)->toDateString(),
        'installment_value' => $pactada,
        'principal_value' => $pactada,
        'interest_value' => '0.00',
        'extra_payment' => '0.00',
        'remaining_balance' => '4000.00',
        'projected_balance' => '4000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => $initialDebt,
        'status' => AmortizationStatus::PARTIAL->value,
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

    return $contract->fresh(['lot', 'installments', 'transactions']);
}

beforeEach(function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);
    $this->bankAccount = BankAccount::query()->create([
        'bank_name' => 'Bancolombia',
        'account_number' => '0101010101',
        'account_type' => 'savings',
        'holder_name' => 'Constructora QA',
    ]);
});

it('en preventa con inicial pendiente aplica primero la inicial y el excedente a la cascada', function () {
    $contract = preventaCascadeContract(LotStatus::PREVENTA->value, '1000.00', '1000.00');

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => 1500,
        'payment_method' => 'transfer',
        'bank_account_id' => $this->bankAccount->id,
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '0258',
    ])->assertCreated();

    $down = $contract->transactions()->where('transaction_type', TransactionType::DOWN_PAYMENT)->get();
    $regular = $contract->transactions()->where('transaction_type', TransactionType::REGULAR_PAYMENT)->get();

    expect($down)->toHaveCount(1)
        ->and((string) $down->first()->amount)->toBe('1000.00')
        ->and($regular)->toHaveCount(1)
        ->and((string) $regular->first()->amount)->toBe('500.00');

    expect($contract->amortizationInstallments()->where('installment_number', 0)->first()->quota_debt)->toBe('0.00')
        ->and($contract->amortizationInstallments()->where('installment_number', 0)->first()->status)->toBe(AmortizationStatus::PAID)
        ->and($contract->amortizationInstallments()->where('installment_number', 1)->first()->quota_debt)->toBe('500.00')
        ->and($contract->amortizationInstallments()->where('installment_number', 2)->first()->quota_debt)->toBe('1000.00');
});

it('en preventa con excedente adjunta el mismo comprobante a inicial y cascada', function () {
    $contract = preventaCascadeContract(LotStatus::PREVENTA->value, '1000.00', '1000.00');
    $file = Illuminate\Http\UploadedFile::fake()->create('consignacion.pdf', 20, 'application/pdf');

    $this->post('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => 1500,
        'payment_method' => 'transfer',
        'bank_account_id' => $this->bankAccount->id,
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '0258',
        'receipt' => $file,
    ], ['Accept' => 'application/json'])->assertCreated();

    $down = $contract->transactions()->where('transaction_type', TransactionType::DOWN_PAYMENT)->with('receipt')->first();
    $regular = $contract->transactions()->where('transaction_type', TransactionType::REGULAR_PAYMENT)->with('receipt')->first();

    expect($down?->receipt)->not->toBeNull()
        ->and($regular?->receipt)->not->toBeNull()
        ->and($regular->receipt->id)->not->toBe($down->receipt->id)
        ->and($regular->receipt->file_path)->toBe($down->receipt->file_path)
        ->and($regular->receipt->file_name)->toBe($down->receipt->file_name);
});

it('en preventa con inicial pendiente y monto igual solo cubre la inicial', function () {
    $contract = preventaCascadeContract(LotStatus::PREVENTA->value, '1000.00', '1000.00');

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => 1000,
        'payment_method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '0258',
    ])->assertCreated();

    expect($contract->transactions()->where('transaction_type', TransactionType::DOWN_PAYMENT)->count())->toBe(1)
        ->and($contract->transactions()->where('transaction_type', TransactionType::REGULAR_PAYMENT)->count())->toBe(0)
        ->and($contract->amortizationInstallments()->where('installment_number', 1)->first()->quota_debt)->toBe('1000.00');
});

it('en preventa con inicial ya saldada cobra solo regulares', function () {
    $contract = preventaCascadeContract(LotStatus::PREVENTA->value, '1000.00', '0.00');
    $contract->amortizationInstallments()->where('installment_number', 0)->update([
        'status' => AmortizationStatus::PAID->value,
        'quota_debt' => '0.00',
    ]);
    Transaction::query()->create([
        'contract_id' => $contract->id,
        'transaction_type' => TransactionType::DOWN_PAYMENT,
        'amount' => '1000.00',
        'transaction_date' => now()->subMonth()->toDateString(),
        'payment_method' => 'cash',
    ]);

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => 1000,
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '0258',
    ])->assertCreated();

    expect($contract->transactions()->where('transaction_type', TransactionType::REGULAR_PAYMENT)->count())->toBe(1)
        ->and($contract->transactions()->where('transaction_type', TransactionType::DOWN_PAYMENT)->count())->toBe(1)
        ->and($contract->amortizationInstallments()->where('installment_number', 1)->first()->quota_debt)->toBe('0.00');
});

it('rechaza el cobro HTTP si hay excedente y no viene destino', function () {
    $contract = preventaCascadeContract(LotStatus::VENDIDO->value, '1000.00', '0.00');
    $contract->amortizationInstallments()
        ->where('installment_number', '>', 1)
        ->update([
            'status' => AmortizationStatus::PENDING->value,
            'due_date' => now()->addMonth()->toDateString(),
        ]);
    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => 1500,
        'transaction_date' => now()->toDateString(),
        'selected_installments' => [$cuota1->id],
        'receipt_number' => '0258',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payment_option'])
        ->assertJsonPath('errors.payment_option.0', CascadeCollectionService::SURPLUS_ACTION_REQUIRED);

    expect($contract->fresh()->transactions()->count())->toBe(0);
});

it('acepta abono_capital como destino HTTP del excedente y no lo trata como adelanto', function () {
    $contract = preventaCascadeContract(LotStatus::VENDIDO->value, '1000.00', '0.00');
    $contract->amortizationInstallments()
        ->where('installment_number', '>', 1)
        ->update([
            'status' => AmortizationStatus::PENDING->value,
            'due_date' => now()->addMonth()->toDateString(),
        ]);
    $cuota1 = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => 1500,
        'transaction_date' => now()->toDateString(),
        'selected_installments' => [$cuota1->id],
        'payment_option' => 'abono_capital',
        'receipt_number' => '0258',
    ])->assertCreated();

    $cuota1->refresh();
    $cuota2 = $contract->amortizationInstallments()->where('installment_number', 2)->first();

    expect($cuota1->status)->toBe(AmortizationStatus::PAID)
        ->and($cuota1->extra_payment)->toBe('500.00')
        ->and($cuota2)->not->toBeNull()
        ->and($cuota2->status)->toBe(AmortizationStatus::PENDING)
        ->and($cuota2->quota_debt)->toBe('1000.00')
        ->and((float) $cuota2->extra_payment)->toBe(0.0)
        ->and($contract->transactions()->latest('id')->first()?->payment_option)->toBe('abono_capital');
});

it('en lote que no es preventa no desvia el pago a la inicial', function () {
    $contract = preventaCascadeContract(LotStatus::DISPONIBLE->value, '1000.00', '1000.00');

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => 1000,
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '0258',
    ])->assertCreated();

    expect($contract->transactions()->where('transaction_type', TransactionType::DOWN_PAYMENT)->count())->toBe(0)
        ->and($contract->transactions()->where('transaction_type', TransactionType::REGULAR_PAYMENT)->count())->toBe(1)
        ->and($contract->amortizationInstallments()->where('installment_number', 0)->first()->quota_debt)->toBe('1000.00')
        ->and($contract->amortizationInstallments()->where('installment_number', 1)->first()->quota_debt)->toBe('0.00');
});

it('rechaza tarjeta en un pago nuevo de cascada', function () {
    $contract = preventaCascadeContract(LotStatus::DISPONIBLE->value, '1000.00', '1000.00');

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => 1000,
        'payment_method' => 'card',
        'transaction_date' => now()->toDateString(),
    ])->assertStatus(422)->assertJsonValidationErrors(['payment_method']);
});

it('rechaza un pago nuevo de cascada sin recibo #', function () {
    $contract = preventaCascadeContract(LotStatus::DISPONIBLE->value, '1000.00', '1000.00');

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => 1000,
        'payment_method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '',
    ])->assertStatus(422)->assertJsonValidationErrors(['receipt_number']);
});

it('guarda Recibo # en columna, notes y bitácora', function () {
    $contract = preventaCascadeContract(LotStatus::DISPONIBLE->value, '1000.00', '1000.00');

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => 1000,
        'payment_method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '0258, 0289',
    ])->assertCreated();

    $tx = $contract->transactions()->where('transaction_type', TransactionType::REGULAR_PAYMENT)->first();
    expect($tx->receipt_number)->toBe('0258-0289')
        ->and($tx->notes)->toBe('Recibo #0258-0289');

    $log = Activity::query()
        ->where('subject_type', $contract::class)
        ->where('subject_id', $contract->id)
        ->where('properties->transaction_id', $tx->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->description)->toBe('Registró un pago de $1,000.00 mediante cash sobre el contrato (Recibo #0258-0289)')
        ->and($log->properties['receipt_number'] ?? null)->toBe('0258-0289');
});

it('en preventa guarda Recibo # en la transacción de inicial', function () {
    $contract = preventaCascadeContract(LotStatus::PREVENTA->value, '1000.00', '1000.00');

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => 1000,
        'payment_method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '0101',
    ])->assertCreated();

    $tx = $contract->transactions()->where('transaction_type', TransactionType::DOWN_PAYMENT)->first();
    expect($tx->receipt_number)->toBe('0101')
        ->and($tx->notes)->toBe('Recibo #0101');
});
