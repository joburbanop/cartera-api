<?php

use App\Enums\AmortizationStatus;
use App\Enums\PaymentMethod;
use App\Enums\ResidualBalanceStatus;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\ContractResidualBalance;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Services\Financial\LifeSheet\ContractLifeSheetService;
use App\Services\Residual\ResidualBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function residualCollectionContract(int $regularCount = 3): Contract
{
    $suffix = substr(str_replace('.', '', uniqid('', true)), -8);

    $project = Project::create([
        'name' => 'Proyecto Cobro Residual',
        'description' => 'Fixture',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $customer = Customer::create([
        'document_type' => 'CC',
        'document_number' => '300'.$suffix,
        'name' => 'Cliente Residual Cobro',
        'phone' => '310'.$suffix,
    ]);
    $lot = Lot::create([
        'project_id' => $project->id,
        'number' => 'RC-'.$suffix,
        'area_m2' => 80,
        'price_m2' => 1000,
        'list_price' => 80000,
        'status' => 'disponible',
        'type' => 'residential',
    ]);
    $contract = Contract::create([
        'contract_number' => 'CT-RES-COL-'.$suffix,
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'seller_name' => 'Vendedor',
        'sale_price' => 4000,
        'down_payment_pactada' => '0.00',
        'term_months' => max(1, $regularCount),
        'interest_rate' => 0,
        'start_date' => now()->subMonths(3)->toDateString(),
        'initial_payment_date' => now()->subMonths(3)->toDateString(),
        'first_installment_date' => now()->subMonths(2)->toDateString(),
        'regular_payment_start_date' => now()->subMonths(2)->toDateString(),
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    for ($number = 1; $number <= $regularCount; $number++) {
        $contract->amortizationInstallments()->create([
            'contract_id' => $contract->id,
            'installment_number' => $number,
            'due_date' => now()->subMonths($regularCount - $number)->toDateString(),
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

    return $contract->fresh(['amortizationInstallments']);
}

function seedPendingResiduals(Contract $contract, array $amounts): void
{
    $installments = $contract->amortizationInstallments()
        ->where('installment_number', '>', 0)
        ->orderBy('installment_number')
        ->get()
        ->values();

    foreach ($amounts as $index => $amount) {
        $installment = $installments[$index] ?? null;
        expect($installment)->not->toBeNull();

        ContractResidualBalance::query()->create([
            'contract_id' => $contract->id,
            'amortization_installment_id' => $installment->id,
            'amount' => $amount,
            'status' => ResidualBalanceStatus::PENDIENTE,
        ]);
    }
}

function residualReceipt(): UploadedFile
{
    return UploadedFile::fake()->create('recibo.pdf', 20, 'application/pdf');
}

function postResidualCollection(int $contractId, string $amount, bool $withReceipt = true, array $extra = [])
{
    $payload = array_merge([
        'contract_id' => $contractId,
        'amount' => $amount,
        'payment_method' => PaymentMethod::TRANSFER->value,
        'bank_account_id' => test()->bankAccount->id,
        'transaction_date' => '2026-09-09',
    ], $extra);

    if ($withReceipt) {
        $payload['receipt'] = residualReceipt();
    }

    return test()->post('/api/collections/residual', $payload, ['Accept' => 'application/json']);
}

beforeEach(function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);
    $this->bankAccount = \App\Models\BankAccount::query()->create([
        'bank_name' => 'Bancolombia',
        'account_number' => '0101010101',
        'account_type' => 'savings',
        'holder_name' => 'Constructora QA',
    ]);
});

it('cobra FIFO y reduce la última fila si el monto no cierra un corte exacto', function () {
    $contract = residualCollectionContract();
    seedPendingResiduals($contract, ['200.00', '200.00', '200.00']);
    $beforeInstallments = AmortizationInstallment::query()
        ->where('contract_id', $contract->id)
        ->orderBy('id')
        ->get(['id', 'quota_debt', 'status', 'interest_paid', 'principal_paid'])
        ->toArray();

    $response = postResidualCollection($contract->id, '500.00', true, ['receipt_number' => '0448-0449']);

    $response->assertCreated()
        ->assertJsonPath('data.amount', '500.00')
        ->assertJsonPath('data.pending_residual_balance', '100.00')
        ->assertJsonPath('data.residual_balance_collectible', false);

    $rows = ContractResidualBalance::query()
        ->where('contract_id', $contract->id)
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->status)->toBe(ResidualBalanceStatus::COBRADO)
        ->and($rows[1]->status)->toBe(ResidualBalanceStatus::COBRADO)
        ->and(number_format((float) $rows[2]->amount, 2, '.', ''))->toBe('100.00')
        ->and($rows[2]->status)->toBe(ResidualBalanceStatus::PENDIENTE)
        ->and($rows[2]->collected_transaction_id)->toBeNull();

    $tx = Transaction::query()->findOrFail($response->json('data.transaction_id'));
    expect($tx->transaction_type)->toBe(TransactionType::RESIDUAL_COLLECTION)
        ->and(number_format((float) $tx->amount, 2, '.', ''))->toBe('500.00')
        ->and($tx->receipt)->not->toBeNull();

    expect(TransactionAllocation::query()->where('transaction_id', $tx->id)->count())->toBe(0);

    $afterInstallments = AmortizationInstallment::query()
        ->where('contract_id', $contract->id)
        ->orderBy('id')
        ->get(['id', 'quota_debt', 'status', 'interest_paid', 'principal_paid'])
        ->toArray();
    expect($afterInstallments)->toBe($beforeInstallments);
});

it('cobra el acumulado completo y deja SUM 0', function () {
    $contract = residualCollectionContract();
    seedPendingResiduals($contract, ['200.00', '200.00', '200.00']);

    postResidualCollection($contract->id, '600.00', true, ['receipt_number' => '0448-0449'])
        ->assertCreated()
        ->assertJsonPath('data.pending_residual_balance', '0.00')
        ->assertJsonPath('data.residual_balance_collectible', false);

    expect(ContractResidualBalance::query()
        ->where('contract_id', $contract->id)
        ->where('status', ResidualBalanceStatus::PENDIENTE)
        ->count())->toBe(0)
        ->and(app(ResidualBalanceService::class)->pendingSum($contract->id))->toBe('0.00');
});

it('responde 422 si el monto excede el pendiente', function () {
    $contract = residualCollectionContract();
    seedPendingResiduals($contract, ['200.00', '200.00', '200.00']);

    postResidualCollection($contract->id, '700.00', true, ['receipt_number' => '0448-0449'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);

    expect(Transaction::query()->where('transaction_type', TransactionType::RESIDUAL_COLLECTION)->count())->toBe(0)
        ->and(ContractResidualBalance::query()->where('status', ResidualBalanceStatus::PENDIENTE)->count())->toBe(3);
});

it('responde 422 si falta el recibo', function () {
    $contract = residualCollectionContract();
    seedPendingResiduals($contract, ['200.00', '200.00', '200.00']);

    postResidualCollection($contract->id, '500.00', false)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['receipt']);
});

it('responde 422 si falta el recibo # en un cobro residual nuevo', function () {
    $contract = residualCollectionContract();
    seedPendingResiduals($contract, ['200.00', '200.00', '200.00']);

    postResidualCollection($contract->id, '500.00', true, [
        'receipt_number' => '',
    ])->assertStatus(422)->assertJsonValidationErrors(['receipt_number']);
});

it('responde 422 si el SUM pendiente no llega al umbral de cobro', function () {
    $contract = residualCollectionContract(1);
    seedPendingResiduals($contract, ['256.00']);

    postResidualCollection($contract->id, '256.00', true, ['receipt_number' => '0448-0449'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);

    expect(ContractResidualBalance::query()->where('status', ResidualBalanceStatus::PENDIENTE)->count())->toBe(1);
});

it('aparece en la hoja de vida como residuales menores y no crea allocations', function () {
    $contract = residualCollectionContract();
    seedPendingResiduals($contract, ['250.00', '250.00']);

    postResidualCollection($contract->id, '500.00', true, ['receipt_number' => '0448-0449'])->assertCreated();

    $sheet = app(ContractLifeSheetService::class)->build($contract->fresh());
    $concepts = collect($sheet['payments'] ?? $sheet['rows'] ?? [])
        ->pluck('concept')
        ->filter()
        ->all();

    expect($concepts)->toContain('RESIDUALES MENORES')
        ->and(TransactionAllocation::query()->count())->toBe(0);
});

it('guarda Recibo # en columna y notes al cobrar residual', function () {
    $contract = residualCollectionContract();
    seedPendingResiduals($contract, ['250.00', '250.00']);

    postResidualCollection($contract->id, '500.00', true, [
        'receipt_number' => '0448, 0449',
    ])->assertCreated();

    $tx = $contract->transactions()->where('transaction_type', TransactionType::RESIDUAL_COLLECTION)->first();
    expect($tx->receipt_number)->toBe('0448-0449')
        ->and($tx->notes)->toBe('Recibo #0448-0449 | Cobro de residuales menores acumulados');
});

it('rechaza tarjeta en un cobro residual nuevo', function () {
    $contract = residualCollectionContract();
    seedPendingResiduals($contract, ['250.00', '250.00']);

    postResidualCollection($contract->id, '500.00', true, [
        'payment_method' => 'card',
    ])->assertStatus(422)->assertJsonValidationErrors(['payment_method']);
});
