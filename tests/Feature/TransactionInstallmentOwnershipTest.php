<?php

use App\Enums\AmortizationStatus;
use App\Enums\RoleName;
use App\Models\AmortizationInstallment;
use App\Services\Financial\Transaction\TransactionService;
use App\Models\BankAccount;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);
    $this->bankAccount = BankAccount::query()->create([
        'bank_name' => 'Bancolombia',
        'account_number' => '0101010101',
        'account_type' => 'savings',
        'holder_name' => 'Constructora QA',
    ]);
});

function contractWithInstallment(int $installmentNumber = 1): array
{
    $project = Project::query()->create([
        'name' => 'Proyecto cuotas '.uniqid(),
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    $lot = Lot::factory()->create(['project_id' => $project->id]);
    $contract = Contract::factory()->create([
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'status' => 'activo',
        'sale_price' => 7000000,
        'down_payment_pactada' => 2000000,
        'term_months' => 5,
        'interest_rate' => 0,
    ]);
    $contract->installments()->delete();

    $installment = AmortizationInstallment::query()->create([
        'contract_id' => $contract->id,
        'installment_number' => $installmentNumber,
        'due_date' => now()->toDateString(),
        'installment_value' => '1000000.00',
        'extra_payment' => '0.00',
        'interest_value' => '0.00',
        'principal_value' => '1000000.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'quota_debt' => '1000000.00',
        'remaining_balance' => '5000000.00',
        'projected_balance' => '5000000.00',
        'status' => AmortizationStatus::PENDING->value,
    ]);

    return [$contract, $installment];
}

it('rechaza IDs de cuotas de otro contrato', function () {
    [$contractA] = contractWithInstallment(1);
    [, $foreignInstallment] = contractWithInstallment(1);

    $this->postJson("/api/contracts/{$contractA->id}/transactions", [
        'amount' => 1000000,
        'payment_method' => 'transfer',
        'bank_account_id' => $this->bankAccount->id,
        'transaction_type' => 'regular_payment',
        'selected_installments' => [$foreignInstallment->id],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('selected_installments.0')
        ->assertJsonFragment(['La cuota no pertenece a este contrato.']);
});

it('permite la cuota inicial (installment_number = 0) en un abono de inicial', function () {
    [$contract, $initial] = contractWithInstallment(0);

    expect((int) $initial->installment_number)->toBe(0);

    $this->postJson("/api/contracts/{$contract->id}/transactions", [
        'amount' => 1000000,
        'payment_method' => 'transfer',
        'bank_account_id' => $this->bankAccount->id,
        'transaction_type' => 'down_payment',
        'selected_installments' => [$initial->id],
        'receipt_number' => '0258',
    ])->assertCreated();
});

it('rechaza regular_payment en POST /transactions y pide usar /collections/cascade', function () {
    [$contract, $installment] = contractWithInstallment(1);

    $this->postJson("/api/contracts/{$contract->id}/transactions", [
        'amount' => 1000000,
        'payment_method' => 'transfer',
        'bank_account_id' => $this->bankAccount->id,
        'transaction_type' => 'regular_payment',
        'selected_installments' => [$installment->id],
        'receipt_number' => '0258',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('transaction_type')
        ->assertJsonFragment([TransactionService::REGULAR_PAYMENT_USE_CASCADE]);
});

it('rechaza extraordinary_payment en POST /transactions y pide usar /collections/cascade', function () {
    [$contract, $installment] = contractWithInstallment(1);

    $this->postJson("/api/contracts/{$contract->id}/transactions", [
        'amount' => 1000000,
        'payment_method' => 'transfer',
        'bank_account_id' => $this->bankAccount->id,
        'transaction_type' => 'extraordinary_payment',
        'payment_option' => 'reducir_plazo',
        'selected_installments' => [$installment->id],
        'receipt_number' => '0258',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('transaction_type')
        ->assertJsonFragment([TransactionService::EXTRAORDINARY_PAYMENT_USE_CASCADE]);
});

it('rechaza refund en POST /transactions', function () {
    [$contract, $installment] = contractWithInstallment(1);

    $this->postJson("/api/contracts/{$contract->id}/transactions", [
        'amount' => 1000000,
        'payment_method' => 'transfer',
        'bank_account_id' => $this->bankAccount->id,
        'transaction_type' => 'refund',
        'selected_installments' => [$installment->id],
        'receipt_number' => '0258',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('transaction_type')
        ->assertJsonFragment([TransactionService::REFUND_NOT_ACCEPTED]);
});

it('rechaza el default implícito a regular_payment cuando hay cuotas seleccionadas', function () {
    [$contract, $installment] = contractWithInstallment(1);

    $this->postJson("/api/contracts/{$contract->id}/transactions", [
        'amount' => 1000000,
        'payment_method' => 'transfer',
        'bank_account_id' => $this->bankAccount->id,
        'selected_installments' => [$installment->id],
        'receipt_number' => '0258',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('transaction_type')
        ->assertJsonFragment([TransactionService::REGULAR_PAYMENT_USE_CASCADE]);
});
