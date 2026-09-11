<?php

use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Services\Collection\CascadeCollectionService;
use App\Services\Collection\PaymentReversalService;
use App\Services\Collection\PreventaThenCascadeCollectionService;
use App\Services\Collection\SplitPaymentService;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use App\Services\Residual\ResidualCollectionService;
use App\Support\ContractCollectionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;

uses(RefreshDatabase::class);

function closedGuardContract(string $status = 'activo'): Contract
{
    $suffix = (string) random_int(100000, 999999);

    $project = Project::query()->create([
        'name' => 'Proyecto Cerrado '.$suffix,
        'description' => 'Fixture caso 38',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => 'CL-'.$suffix,
    ]);

    $contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'sale_price' => '2000.00',
        'down_payment_pactada' => '0.00',
        'term_months' => 2,
        'interest_rate' => 0,
        'status' => $status,
        'start_date' => now()->subMonths(2)->toDateString(),
        'first_installment_date' => now()->subMonth()->toDateString(),
        'regular_payment_start_date' => now()->subMonth()->toDateString(),
    ]);

    $contract->amortizationInstallments()->create([
        'contract_id' => $contract->id,
        'installment_number' => 1,
        'due_date' => now()->subMonth()->toDateString(),
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

beforeEach(function () {
    $this->admin = $this->actingAsRole(RoleName::ADMINISTRADOR->value);
});

it('los servicios de cobro validan el estado cerrado y la reversa no', function () {
    $collection = [
        CascadeCollectionService::class,
        PreventaThenCascadeCollectionService::class,
        SplitPaymentService::class,
        DownPaymentService::class,
        ResidualCollectionService::class,
    ];

    foreach ($collection as $class) {
        $src = (string) file_get_contents((new ReflectionClass($class))->getFileName());
        expect($src)->toContain('ContractCollectionGuard::assertAcceptsPayments');
    }

    $reversalSrc = (string) file_get_contents((new ReflectionClass(PaymentReversalService::class))->getFileName());
    expect($reversalSrc)->not->toContain('ContractCollectionGuard');
});

it('rechaza un cobro sobre un contrato terminado y no crea transacción ni toca cuotas', function () {
    $contract = closedGuardContract(ContractStatus::TERMINADO->value);
    $installment = $contract->amortizationInstallments()->where('installment_number', 1)->firstOrFail();
    $snapshot = $installment->only([
        'status',
        'quota_debt',
        'principal_paid',
        'interest_paid',
        'extra_payment',
        'remaining_balance',
    ]);

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => '1000.00',
        'payment_method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '038-TERM',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['contract'])
        ->assertJsonPath(
            'errors.contract.0',
            ContractCollectionGuard::closedMessage(ContractStatus::TERMINADO),
        );

    expect(Transaction::query()->where('contract_id', $contract->id)->count())->toBe(0);

    $fresh = $installment->fresh();
    expect($fresh->status->value)->toBe($snapshot['status'] instanceof AmortizationStatus
        ? $snapshot['status']->value
        : (string) $snapshot['status'])
        ->and((string) $fresh->quota_debt)->toBe((string) $snapshot['quota_debt'])
        ->and((string) $fresh->principal_paid)->toBe((string) $snapshot['principal_paid'])
        ->and((string) $fresh->interest_paid)->toBe((string) $snapshot['interest_paid'])
        ->and((string) $fresh->extra_payment)->toBe((string) $snapshot['extra_payment'])
        ->and((string) $fresh->remaining_balance)->toBe((string) $snapshot['remaining_balance']);
});

it('rechaza un cobro sobre un contrato rescindido', function () {
    $contract = closedGuardContract(ContractStatus::RESCINDIDO->value);

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => '1000.00',
        'payment_method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '038-RESC',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['contract'])
        ->assertJsonPath(
            'errors.contract.0',
            ContractCollectionGuard::closedMessage(ContractStatus::RESCINDIDO),
        );

    expect(Transaction::query()->where('contract_id', $contract->id)->count())->toBe(0);
});

it('sigue aceptando cobros sobre un contrato vencido', function () {
    $contract = closedGuardContract(ContractStatus::VENCIDO->value);

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => '1000.00',
        'payment_method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '038-VENC',
    ])->assertCreated();

    $installment = AmortizationInstallment::query()
        ->where('contract_id', $contract->id)
        ->where('installment_number', 1)
        ->firstOrFail();

    expect(Transaction::query()->where('contract_id', $contract->id)->count())->toBe(1)
        ->and($installment->status)->toBe(AmortizationStatus::PAID)
        ->and((string) $installment->quota_debt)->toBe('0.00');
});

it('permite revertir el último cobro de un contrato que ya está terminado', function () {
    $contract = closedGuardContract(ContractStatus::ACTIVO->value);

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => '1000.00',
        'payment_method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '038-REV',
    ])->assertCreated();

    $tx = Transaction::query()
        ->where('contract_id', $contract->id)
        ->where('transaction_type', TransactionType::REGULAR_PAYMENT)
        ->firstOrFail();

    $contract->update(['status' => ContractStatus::TERMINADO->value]);

    $this->postJson("/api/contracts/{$contract->id}/transactions/{$tx->id}/reversal", [
        'reason' => 'error_captura',
    ])->assertCreated();

    expect($tx->fresh()->reversed_at)->not->toBeNull();

    $installment = AmortizationInstallment::query()
        ->where('contract_id', $contract->id)
        ->where('installment_number', 1)
        ->firstOrFail();

    expect($installment->status)->not->toBe(AmortizationStatus::PAID)
        ->and((string) $installment->quota_debt)->toBe('1000.00')
        ->and((string) $installment->principal_paid)->toBe('0.00');
});
