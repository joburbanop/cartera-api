<?php

namespace Tests\Feature;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmortizationRealScenariosTest extends TestCase
{
    use RefreshDatabase;

    private Contract $contract;

    private AmortizationInstallment $initialInstallment;

    private AmortizationInstallment $installment1;

    private AmortizationInstallment $installment2;

    private AmortizationInstallment $installment3;

    private AmortizationInstallment $installment4;

    private AmortizationInstallment $installment5;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->actingAsRole('administrador');

        $project = Project::query()->create([
            'name' => 'Proyecto QA Real Scenarios',
            'description' => 'Escenarios reales de recaudo en cascada',
            'location' => 'Bogota',
            'status' => 'active',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $customer = Customer::factory()->create();
        $lot = Lot::factory()->create([
            'project_id' => $project->id,
        ]);

        $this->contract = Contract::factory()->create([
            'customer_id' => $customer->id,
            'lot_id' => $lot->id,
            'sale_price' => 7000000,
            'down_payment_pactada' => 2000000,
            'term_months' => 5,
            'interest_rate' => 0,
            'status' => 'activo',
            'start_date' => now()->subMonths(2)->toDateString(),
            'initial_payment_date' => now()->subMonths(2)->toDateString(),
            'first_installment_date' => now()->subMonth()->toDateString(),
            'regular_payment_start_date' => now()->subMonth()->toDateString(),
            'preventa_installments_count' => 0,
        ]);

        $baseDueDate = now()->subMonth()->startOfDay();

        $this->initialInstallment = $this->createInstallment(
            installmentNumber: 0,
            dueDate: $baseDueDate->copy()->subMonth()->toDateString(),
            installmentValue: '2000000.00',
            remainingBalance: '5000000.00'
        );

        $this->installment1 = $this->createInstallment(
            installmentNumber: 1,
            dueDate: $baseDueDate->copy()->toDateString(),
            installmentValue: '1000000.00',
            remainingBalance: '5000000.00'
        );

        $this->installment2 = $this->createInstallment(
            installmentNumber: 2,
            dueDate: $baseDueDate->copy()->addMonth()->toDateString(),
            installmentValue: '1000000.00',
            remainingBalance: '4000000.00'
        );

        $this->installment3 = $this->createInstallment(
            installmentNumber: 3,
            dueDate: $baseDueDate->copy()->addMonths(2)->toDateString(),
            installmentValue: '1000000.00',
            remainingBalance: '3000000.00'
        );

        $this->installment4 = $this->createInstallment(
            installmentNumber: 4,
            dueDate: $baseDueDate->copy()->addMonths(3)->toDateString(),
            installmentValue: '1000000.00',
            remainingBalance: '2000000.00'
        );

        $this->installment5 = $this->createInstallment(
            installmentNumber: 5,
            dueDate: $baseDueDate->copy()->addMonths(4)->toDateString(),
            installmentValue: '1000000.00',
            remainingBalance: '1000000.00'
        );
    }

    public function test_pago_normal_a_tiempo(): void
    {
        $response = $this->postJson('/api/collections/cascade', [
            'contract_id' => $this->contract->id,
            'amount' => '1000000.00',
            'payment_date' => now()->toDateString(),
            'selected_installments' => [$this->installment1->id],
        ]);

        $response->assertCreated();

        $this->installment1->refresh();

        $this->assertSame(AmortizationStatus::PAID, $this->installment1->status);
        $this->assertSame('1000000.00', $this->paidAmount($this->installment1));
    }

    public function test_recibos_multiples_abono_parcial(): void
    {
        $firstResponse = $this->postJson('/api/collections/cascade', [
            'contract_id' => $this->contract->id,
            'amount' => '400000.00',
            'payment_date' => now()->subDays(2)->toDateString(),
            'selected_installments' => [$this->installment1->id],
        ]);

        $firstResponse->assertCreated();

        $this->installment1->refresh();
        $this->assertSame(AmortizationStatus::PARTIAL, $this->installment1->status);
        $this->assertSame('400000.00', $this->paidAmount($this->installment1));

        $secondResponse = $this->postJson('/api/collections/cascade', [
            'contract_id' => $this->contract->id,
            'amount' => '600000.00',
            'payment_date' => now()->toDateString(),
            'selected_installments' => [$this->installment1->id],
        ]);

        $secondResponse->assertCreated();

        $this->installment1->refresh();
        $this->assertSame(AmortizationStatus::PAID, $this->installment1->status);
        $this->assertSame('1000000.00', $this->paidAmount($this->installment1));
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_recaudo_mora_acumulada_regla_fifo(): void
    {
        $response = $this->postJson('/api/collections/cascade', [
            'contract_id' => $this->contract->id,
            'amount' => '3000000.00',
            'payment_date' => now()->toDateString(),
            'selected_installments' => [
                $this->installment1->id,
                $this->installment2->id,
                $this->installment3->id,
            ],
        ]);

        $response->assertCreated();

        $this->installment1->refresh();
        $this->installment2->refresh();
        $this->installment3->refresh();

        $this->assertSame(AmortizationStatus::PAID, $this->installment1->status);
        $this->assertSame(AmortizationStatus::PAID, $this->installment2->status);
        $this->assertSame(AmortizationStatus::PAID, $this->installment3->status);
        $this->assertSame('1000000.00', $this->paidAmount($this->installment1));
        $this->assertSame('1000000.00', $this->paidAmount($this->installment2));
        $this->assertSame('1000000.00', $this->paidAmount($this->installment3));
    }

    public function test_abono_extra_a_capital_excedente(): void
    {
        $response = $this->postJson('/api/collections/cascade', [
            'contract_id' => $this->contract->id,
            'amount' => '5000000.00',
            'payment_option' => 'reducir_plazo',
            'payment_date' => now()->toDateString(),
            'selected_installments' => [
                $this->installment1->id,
                $this->installment2->id,
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.installments.0.amount_applied', '1000000.00');
        $response->assertJsonPath('data.installments.1.amount_applied', '4000000.00');

        $this->installment1->refresh();
        $this->installment2->refresh();

        $this->assertSame(AmortizationStatus::PAID, $this->installment1->status);
        $this->assertSame(AmortizationStatus::PAID, $this->installment2->status);
        $this->assertSame('3000000.00', number_format((float) $this->installment2->extra_payment, 2, '.', ''));
    }

    public function test_pago_insuficiente_para_mora(): void
    {
        $response = $this->postJson('/api/collections/cascade', [
            'contract_id' => $this->contract->id,
            'amount' => '1500000.00',
            'payment_date' => now()->toDateString(),
            'selected_installments' => [
                $this->installment1->id,
                $this->installment2->id,
            ],
        ]);

        $response->assertCreated();

        $this->installment1->refresh();
        $this->installment2->refresh();

        $this->assertSame(AmortizationStatus::PAID, $this->installment1->status);
        $this->assertSame(AmortizationStatus::PARTIAL, $this->installment2->status);
        $this->assertSame('1000000.00', $this->paidAmount($this->installment1));
        $this->assertSame('500000.00', $this->paidAmount($this->installment2));
        $this->assertSame('500000.00', number_format((float) $this->installment2->quota_debt, 2, '.', ''));
    }

    public function test_separacion_tuberias_cuota_inicial_vs_ordinaria(): void
    {
        $response = $this->postJson('/api/collections/cascade', [
            'contract_id' => $this->contract->id,
            'amount' => '2500000.00',
            'payment_date' => now()->toDateString(),
            'selected_installments' => [
                $this->initialInstallment->id,
                $this->installment1->id,
            ],
        ]);

        if ($response->status() === 422) {
            $response->assertStatus(422);
            return;
        }

        $response->assertCreated();

        $this->initialInstallment->refresh();
        $this->installment1->refresh();

        // Esta asercion valida que el flujo de cascade no procese cuota inicial.
        $this->assertSame('2000000.00', number_format((float) $this->initialInstallment->quota_debt, 2, '.', ''));
        $this->assertSame(AmortizationStatus::PENDING, $this->initialInstallment->status);

        $this->assertSame(AmortizationStatus::PAID, $this->installment1->status);
    }

    private function createInstallment(
        int $installmentNumber,
        string $dueDate,
        string $installmentValue,
        string $remainingBalance,
    ): AmortizationInstallment {
        return $this->contract->amortizationInstallments()->create([
            'contract_id' => $this->contract->id,
            'installment_number' => $installmentNumber,
            'due_date' => $dueDate,
            'installment_value' => $installmentValue,
            'extra_payment' => '0.00',
            'interest_value' => '0.00',
            'principal_value' => $installmentValue,
            'interest_paid' => '0.00',
            'principal_paid' => '0.00',
            'quota_debt' => $installmentValue,
            'remaining_balance' => $remainingBalance,
            'projected_balance' => $remainingBalance,
            'status' => AmortizationStatus::PENDING->value,
        ]);
    }

    private function paidAmount(AmortizationInstallment $installment): string
    {
        $paid = (float) $installment->installment_value - (float) $installment->quota_debt;

        return number_format(max(0, $paid), 2, '.', '');
    }
}
