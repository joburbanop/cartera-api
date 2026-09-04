<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class UpdateInstallmentPaymentDateTest extends TestCase
{
    use RefreshDatabase;

    private Contract $contract;

    private AmortizationInstallment $installment;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->actingAsRole(RoleName::ADMINISTRADOR->value);
        $project = Project::query()->create([
            'name' => 'Proyecto Fecha Pago',
            'description' => 'Fixture payment_date',
            'location' => 'Bogota',
            'status' => 'active',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $customer = Customer::factory()->create();
        $lot = Lot::factory()->create(['project_id' => $project->id]);

        $this->contract = Contract::factory()->create([
            'customer_id' => $customer->id,
            'lot_id' => $lot->id,
            'sale_price' => 7000000,
            'down_payment_pactada' => 2000000,
            'term_months' => 3,
            'interest_rate' => 0,
            'status' => 'activo',
            'start_date' => '2027-01-01',
            'initial_payment_date' => '2027-01-01',
            'first_installment_date' => '2027-02-05',
            'regular_payment_start_date' => '2027-02-05',
        ]);

        $this->createInstallment(0, '2027-01-05', null);
        $this->installment = $this->createInstallment(1, '2027-02-05', '2027-02-10', 'REC-100');
        $this->createInstallment(2, '2027-03-05', '2027-03-12');
    }

    public function test_actualiza_solo_esa_cuota_y_advierte_si_hay_recibo_o_transaccion(): void
    {
        Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::REGULAR_PAYMENT->value,
            'amount' => '1000000.00',
            'transaction_date' => '2027-02-10',
            'payment_method' => 'cash',
            'notes' => 'no tocar',
        ]);

        $neighbor = AmortizationInstallment::query()
            ->where('contract_id', $this->contract->id)
            ->where('installment_number', 2)
            ->first();

        $response = $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment->id}/payment-date",
            ['payment_date' => '2027-02-20'],
        );

        $response->assertOk()
            ->assertJsonPath('data.warning', 'Esta fecha quedará distinta a la de la transacción o recibo original');

        $this->assertSame('2027-02-20', Carbon::parse((string) $this->installment->fresh()->payment_date)->toDateString());
        $this->assertSame('2027-03-12', Carbon::parse((string) $neighbor->fresh()->payment_date)->toDateString());
        $this->assertSame('2027-02-05', $this->installment->fresh()->due_date->toDateString());
        $this->assertSame('no tocar', Transaction::query()->first()->notes);
        $this->assertSame('2027-02-10', Transaction::query()->first()->transaction_date->toDateString());

        $entry = Activity::query()
            ->where('description', 'like', 'Cambió la fecha de pago%')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(1, (int) $entry->properties['installment_number']);
    }

    public function test_no_arrastra_otras_cuotas_sin_transacciones_y_sin_warning(): void
    {
        $this->installment->update(['receipt_number' => null]);

        $response = $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment->id}/payment-date",
            ['payment_date' => '2027-02-18'],
        );

        $response->assertOk()->assertJsonPath('data.warning', null);
        $this->assertSame('2027-03-12', Carbon::parse(
            (string) AmortizationInstallment::query()
                ->where('contract_id', $this->contract->id)
                ->where('installment_number', 2)
                ->value('payment_date')
        )->toDateString());
    }

    public function test_socio_gerencia_recibe_403(): void
    {
        $this->actingAsRole(RoleName::SOCIO_GERENCIA->value, User::factory()->create());

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment->id}/payment-date",
            ['payment_date' => '2027-02-20'],
        )->assertForbidden();
    }

    private function createInstallment(
        int $number,
        string $dueDate,
        ?string $paymentDate,
        ?string $receipt = null,
    ): AmortizationInstallment {
        return AmortizationInstallment::query()->create([
            'contract_id' => $this->contract->id,
            'installment_number' => $number,
            'due_date' => $dueDate,
            'payment_date' => $paymentDate,
            'receipt_number' => $receipt,
            'installment_value' => '1000000.00',
            'extra_payment' => '0.00',
            'interest_value' => '0.00',
            'principal_value' => '1000000.00',
            'interest_paid' => '0.00',
            'principal_paid' => '0.00',
            'quota_debt' => '1000000.00',
            'remaining_balance' => '5000000.00',
            'projected_balance' => '5000000.00',
            'status' => 'paid',
        ]);
    }
}
