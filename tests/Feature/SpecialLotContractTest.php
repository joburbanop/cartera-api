<?php

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Financial\Amortization\AmortizationService;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('permite down_payment_pactada igual al precio de venta', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $customer = Customer::factory()->create();
    $project = Project::query()->create([
        'name' => 'Proyecto Lote Especial',
        'description' => 'Fixture',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => 'L-ESP-EQ',
        'status' => 'disponible',
    ]);

    $this->postJson('/api/contracts', [
        'contract_number' => 'PROM-ESP-EQ',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'sale_price' => 5000000,
        'down_payment_pactada' => 5000000,
        'term_months' => 12,
        'interest_rate' => 1,
        'start_date' => now()->toDateString(),
        'initial_payment_date' => now()->toDateString(),
        'first_installment_date' => now()->addMonth()->toDateString(),
        'preventa_installments_count' => 0,
    ])->assertCreated();
});

it('con plazo 0 solo genera la fila 0 de cuota inicial', function () {
    $project = Project::query()->create([
        'name' => 'Proyecto Plazo Cero',
        'description' => 'Fixture',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'status' => 'disponible',
    ]);
    $contract = Contract::factory()->create([
        'lot_id' => $lot->id,
        'sale_price' => 4000000,
        'down_payment_pactada' => 4000000,
        'term_months' => 0,
        'interest_rate' => 0,
        'status' => ContractStatus::PREVENTA_INACTIVA->value,
        'is_special_lot' => true,
    ]);

    $plan = app(AmortizationService::class)->generateInitialProjection($contract);

    expect($plan)->toHaveCount(1)
        ->and((int) $plan->first()->installment_number)->toBe(0)
        ->and((string) $plan->first()->interest_value)->toBe('0.00')
        ->and((string) $plan->first()->quota_debt)->toBe('4000000.00')
        ->and((string) $plan->first()->remaining_balance)->toBe('0.00');
});

it('al completar el 100% del abono de un lote especial activa el contrato y vende el lote', function () {
    $this->actingAsRole(RoleName::ADMINISTRADOR->value);

    $customer = Customer::factory()->create();
    $project = Project::query()->create([
        'name' => 'Proyecto Especial Completo',
        'description' => 'Fixture',
        'location' => 'Bogotá',
        'status' => 'active',
    ]);
    $lot = Lot::factory()->create([
        'project_id' => $project->id,
        'number' => 'L-ESP-100',
        'status' => 'disponible',
    ]);

    $this->postJson('/api/contracts', [
        'contract_number' => 'PROM-ESP-100',
        'customer_id' => $customer->id,
        'lot_id' => $lot->id,
        'sale_price' => 8000000,
        'is_special_lot' => true,
        'start_date' => now()->toDateString(),
    ])->assertCreated();

    $contract = Contract::query()->where('contract_number', 'PROM-ESP-100')->firstOrFail();

    expect($contract->is_special_lot)->toBeTrue()
        ->and((float) $contract->down_payment_pactada)->toBe(8000000.0)
        ->and((int) $contract->term_months)->toBe(0)
        ->and($contract->status)->toBe(ContractStatus::PREVENTA_INACTIVA)
        ->and($contract->amortizationInstallments()->count())->toBe(1)
        ->and($lot->fresh()->status)->toBe(LotStatus::PREVENTA);

    $pay = function (string $amount) use ($contract): void {
        app(DownPaymentService::class)->registerDownPayment(new CreateTransactionDTO(
            contractId: $contract->id,
            amount: $amount,
            transactionDate: Carbon::parse(now()->toDateString()),
            paymentMethod: PaymentMethod::CASH,
            transactionType: TransactionType::DOWN_PAYMENT,
            installmentNumbers: [],
        ));
    };

    $pay('3000000.00');

    expect($contract->fresh()->status)->toBe(ContractStatus::PREVENTA_INACTIVA)
        ->and($lot->fresh()->status)->toBe(LotStatus::PREVENTA)
        ->and($contract->amortizationInstallments()->where('installment_number', 0)->first()->status)
        ->toBe(AmortizationStatus::PARTIAL);

    $pay('5000000.00');

    expect($contract->fresh()->status)->toBe(ContractStatus::ACTIVO)
        ->and($lot->fresh()->status)->toBe(LotStatus::VENDIDO)
        ->and($contract->amortizationInstallments()->where('installment_number', 0)->first()->status)
        ->toBe(AmortizationStatus::PAID)
        ->and($contract->amortizationInstallments()->count())->toBe(1);
});
