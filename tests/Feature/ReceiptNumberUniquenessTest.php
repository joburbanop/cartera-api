<?php

use App\Enums\AmortizationStatus;
use App\Enums\LotStatus;
use App\Enums\RoleName;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Support\ReceiptNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function receiptUniqContract(string $lotStatus = 'disponible'): Contract
{
    $suffix = (string) random_int(100000, 999999);
    $project = Project::query()->create([
        'name' => 'Proyecto Recibo '.$suffix,
        'description' => 'Fixture recibo único',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'status' => $lotStatus,
        'number' => 'RC-'.$suffix,
    ]);

    $contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'sale_price' => '5000.00',
        'down_payment_pactada' => $lotStatus === LotStatus::PREVENTA->value ? '1000.00' : '0.00',
        'term_months' => 3,
        'interest_rate' => 0,
        'status' => $lotStatus === LotStatus::PREVENTA->value ? 'preventa_inactiva' : 'activo',
        'start_date' => now()->subMonths(3)->toDateString(),
        'initial_payment_date' => now()->subMonths(3)->toDateString(),
        'first_installment_date' => now()->subMonths(2)->toDateString(),
        'regular_payment_start_date' => now()->subMonths(2)->toDateString(),
    ]);

    if ($lotStatus === LotStatus::PREVENTA->value) {
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
            'principal_paid' => '0.00',
            'quota_debt' => '1000.00',
            'status' => AmortizationStatus::PARTIAL->value,
        ]);
    }

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
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);
});

it('rechaza un segundo cobro con el mismo recibo en el mismo contrato', function () {
    $contract = receiptUniqContract();

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => '1000.00',
        'payment_method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '038-DUP',
    ])->assertCreated();

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => '1000.00',
        'payment_method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '038-DUP',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['receipt_number'])
        ->assertJsonPath('errors.receipt_number.0', ReceiptNumber::DUPLICATE);
});

it('permite el mismo recibo en el par preventa+cascada de un solo POST', function () {
    $contract = receiptUniqContract(LotStatus::PREVENTA->value);

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => '1500.00',
        'payment_method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '038-PAR',
    ])->assertCreated();

    $numbers = $contract->transactions()->pluck('receipt_number')->all();
    expect($numbers)->toHaveCount(2)
        ->and($numbers)->each->toBe('038-PAR');
});

it('permite reutilizar el recibo después de revertir el cobro original', function () {
    $contract = receiptUniqContract();

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => '1000.00',
        'payment_method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '038-REV',
    ])->assertCreated();

    $txId = (int) $contract->transactions()->value('id');

    $this->postJson("/api/contracts/{$contract->id}/transactions/{$txId}/reversal", [
        'reason' => 'error_captura',
    ])->assertCreated();

    $this->postJson('/api/collections/cascade', [
        'contract_id' => $contract->id,
        'amount' => '1000.00',
        'payment_method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'receipt_number' => '038-REV',
    ])->assertCreated();
});
