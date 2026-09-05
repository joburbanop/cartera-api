<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\ContractPaymentPromise;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use App\Services\Financial\Refinancing\AcuerdoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefinanceContractTest extends TestCase
{
    use RefreshDatabase;

    private Contract $contract;

    private AmortizationInstallment $installment0;

    private AmortizationInstallment $installment1;

    private AmortizationInstallment $installment2;

    private AmortizationInstallment $installment3;

    private AmortizationInstallment $installment4;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->actingAsRole(RoleName::ADMINISTRADOR->value);

        $project = Project::query()->create([
            'name' => 'Proyecto Refinanciacion',
            'description' => 'Fixture refinanciacion',
            'location' => 'Bogota',
            'status' => 'active',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $customer = Customer::factory()->create();
        $lot = Lot::factory()->create(['project_id' => $project->id]);

        $this->contract = Contract::factory()->create([
            'customer_id' => $customer->id,
            'lot_id' => $lot->id,
            'sale_price' => '7000000.00',
            'down_payment_pactada' => '2000000.00',
            'term_months' => 4,
            'interest_rate' => '0.00',
            'status' => 'activo',
            'start_date' => '2027-01-01',
            'initial_payment_date' => '2027-01-01',
            'first_installment_date' => '2027-02-05',
            'regular_payment_start_date' => '2027-02-05',
            'preventa_installments_count' => 0,
        ]);

        $this->installment0 = $this->createInstallment($this->contract, 0, '2027-01-05', 'paid', [
            'installment_value' => '2000000.00',
            'principal_value' => '2000000.00',
            'interest_value' => '0.00',
            'quota_debt' => '0.00',
            'remaining_balance' => '5000000.00',
        ]);
        $this->installment1 = $this->createInstallment($this->contract, 1, '2027-02-05', 'paid', [
            'installment_value' => '1000000.00',
            'principal_value' => '1000000.00',
            'quota_debt' => '0.00',
            'remaining_balance' => '4000000.00',
        ]);
        $this->installment2 = $this->createInstallment($this->contract, 2, '2027-03-05', 'overdue', [
            'installment_value' => '1000000.00',
            'principal_value' => '1000000.00',
            'interest_value' => '200000.00',
            'quota_debt' => '1000000.00',
            'remaining_balance' => '3000000.00',
        ]);
        $this->installment3 = $this->createInstallment($this->contract, 3, '2027-04-05', 'pending', [
            'installment_value' => '1000000.00',
            'principal_value' => '800000.00',
            'interest_value' => '200000.00',
            'quota_debt' => '1000000.00',
            'remaining_balance' => '2200000.00',
        ]);
        $this->installment4 = $this->createInstallment($this->contract, 4, '2027-05-05', 'pending', [
            'installment_value' => '1000000.00',
            'principal_value' => '800000.00',
            'interest_value' => '200000.00',
            'quota_debt' => '1000000.00',
            'remaining_balance' => '0.00',
        ]);
    }

    public function test_acuerdo_pago_crea_promesas_de_abono_fijo_sin_tocar_cuotas(): void
    {
        $installmentCount = $this->contract->installments()->count();

        $response = $this->postJson("/api/contracts/{$this->contract->id}/refinance", [
            'tipo' => 'acuerdo_pago',
            'extra_amount' => '250000.00',
            'months' => 3,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Contrato refinanciado exitosamente.');

        $promises = ContractPaymentPromise::query()
            ->where('contract_id', $this->contract->id)
            ->orderBy('payment_number')
            ->get();

        $this->assertCount(3, $promises);
        $this->assertSame(['250000.00', '250000.00', '250000.00'], $promises->pluck('expected_amount')->map(fn ($v) => number_format((float) $v, 2, '.', ''))->all());
        $this->assertSame(
            ['2027-03-05', '2027-04-05', '2027-05-05'],
            $promises->map(fn ($p) => $p->expected_date->toDateString())->all(),
        );
        $this->assertTrue($promises->every(fn ($p) => $p->description === AcuerdoPagoService::DESCRIPTION));
        $this->assertSame($installmentCount, $this->contract->installments()->count());
        $this->assertActivityLogged('acuerdo_pago');
    }

    public function test_acuerdo_pago_rechaza_contrato_no_activo(): void
    {
        $this->contract->update(['status' => 'preventa_inactiva']);

        $this->postJson("/api/contracts/{$this->contract->id}/refinance", [
            'tipo' => 'acuerdo_pago',
            'extra_amount' => '250000.00',
            'months' => 3,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.contract.0', 'Solo se pueden refinanciar contratos en estado activo.');
    }

    public function test_acuerdo_pago_rechaza_datos_invalidos(): void
    {
        $this->postJson("/api/contracts/{$this->contract->id}/refinance", [
            'tipo' => 'acuerdo_pago',
            'extra_amount' => '0',
            'months' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['extra_amount', 'months']);
    }

    public function test_tiempo_gracia_desplaza_todas_las_cuotas_pendientes(): void
    {
        $paidDueDate = $this->installment1->due_date->toDateString();

        $this->postJson("/api/contracts/{$this->contract->id}/refinance", [
            'tipo' => 'tiempo_gracia',
            'months' => 2,
        ])->assertOk();

        $this->assertSame('2027-01-05', $this->installment0->fresh()->due_date->toDateString());
        $this->assertSame($paidDueDate, $this->installment1->fresh()->due_date->toDateString());
        $this->assertSame('2027-05-05', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('2027-06-05', $this->installment3->fresh()->due_date->toDateString());
        $this->assertSame('2027-07-05', $this->installment4->fresh()->due_date->toDateString());
        $this->assertActivityLogged('tiempo_gracia');
    }

    public function test_tiempo_gracia_rechaza_contrato_no_activo(): void
    {
        $this->contract->update(['status' => 'rescindido']);

        $this->postJson("/api/contracts/{$this->contract->id}/refinance", [
            'tipo' => 'tiempo_gracia',
            'months' => 2,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['contract']);
    }

    public function test_tiempo_gracia_rechaza_meses_invalidos(): void
    {
        $this->postJson("/api/contracts/{$this->contract->id}/refinance", [
            'tipo' => 'tiempo_gracia',
            'months' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['months']);
    }

    public function test_refinanciar_saldo_regenera_desde_el_ancla_con_cuota_fija_exacta(): void
    {
        $this->postJson("/api/contracts/{$this->contract->id}/refinance", [
            'tipo' => 'refinanciar_saldo',
            'new_term_months' => 2,
            'new_interest_rate' => '0.00',
        ])->assertOk();

        $this->contract->refresh();
        $this->assertSame(3, (int) $this->contract->term_months);
        $this->assertSame('0.00', number_format((float) $this->contract->interest_rate, 2, '.', ''));

        $paid = $this->installment1->fresh();
        $this->assertSame('paid', $paid->status->value);
        $this->assertSame('4000000.00', number_format((float) $paid->remaining_balance, 2, '.', ''));
        $this->assertSame('1000000.00', number_format((float) $paid->installment_value, 2, '.', ''));

        $future = $this->contract->installments()
            ->where('installment_number', '>=', 2)
            ->orderBy('installment_number')
            ->get();

        $this->assertCount(2, $future);
        $this->assertSame('2000000.00', number_format((float) $future[0]->installment_value, 2, '.', ''));
        $this->assertSame('2000000.00', number_format((float) $future[0]->remaining_balance, 2, '.', ''));
        $this->assertSame('2000000.00', number_format((float) $future[1]->installment_value, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $future[1]->remaining_balance, 2, '.', ''));
        $this->assertActivityLogged('refinanciar_saldo');
    }

    public function test_refinanciar_saldo_con_tasa_recalcula_interes_exacto(): void
    {
        $calculator = app(AmortizationCalculationService::class);
        $quota = $calculator->calculateFixedQuota('4000000.00', '1.00', 2);
        $firstInterest = $calculator->calculateInterest('4000000.00', '1.00');
        $firstPrincipal = $calculator->calculatePrincipal($quota, $firstInterest);
        $midBalance = $calculator->calculateRemainingBalance('4000000.00', $firstPrincipal);
        $lastInterest = $calculator->calculateInterest($midBalance, '1.00');
        $lastInstallment = bcadd($midBalance, $lastInterest, 2);

        $this->postJson("/api/contracts/{$this->contract->id}/refinance", [
            'tipo' => 'refinanciar_saldo',
            'new_term_months' => 2,
            'new_interest_rate' => '1.00',
        ])->assertOk();

        $future = $this->contract->installments()
            ->where('installment_number', '>=', 2)
            ->orderBy('installment_number')
            ->get();

        $this->assertSame($quota, number_format((float) $future[0]->installment_value, 2, '.', ''));
        $this->assertSame($firstInterest, number_format((float) $future[0]->interest_value, 2, '.', ''));
        $this->assertSame($firstPrincipal, number_format((float) $future[0]->principal_value, 2, '.', ''));
        $this->assertSame($lastInstallment, number_format((float) $future[1]->installment_value, 2, '.', ''));
        $this->assertSame($lastInterest, number_format((float) $future[1]->interest_value, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $future[1]->remaining_balance, 2, '.', ''));
        $this->assertSame('1.00', number_format((float) $this->contract->fresh()->interest_rate, 2, '.', ''));
    }

    public function test_refinanciar_saldo_rechaza_contrato_no_activo(): void
    {
        $this->contract->update(['status' => 'terminado']);

        $this->postJson("/api/contracts/{$this->contract->id}/refinance", [
            'tipo' => 'refinanciar_saldo',
            'new_term_months' => 2,
            'new_interest_rate' => '1.00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['contract']);
    }

    public function test_refinanciar_saldo_rechaza_datos_invalidos(): void
    {
        $this->postJson("/api/contracts/{$this->contract->id}/refinance", [
            'tipo' => 'refinanciar_saldo',
            'new_term_months' => 0,
            'new_interest_rate' => '-1',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['new_term_months', 'new_interest_rate']);
    }

    public function test_exoneracion_reduce_interes_con_bcmath_sin_cambiar_capital(): void
    {
        $originalQuotaDebt = number_format((float) $this->installment3->quota_debt, 2, '.', '');

        $this->postJson("/api/contracts/{$this->contract->id}/refinance", [
            'tipo' => 'exoneracion_intereses',
            'installment_ids' => [$this->installment3->id, $this->installment4->id],
            'reduction_percent' => '25',
        ])->assertOk();

        $updated3 = $this->installment3->fresh();
        $updated4 = $this->installment4->fresh();

        $this->assertSame('150000.00', number_format((float) $updated3->interest_value, 2, '.', ''));
        $this->assertSame('800000.00', number_format((float) $updated3->principal_value, 2, '.', ''));
        $this->assertSame('950000.00', number_format((float) $updated3->installment_value, 2, '.', ''));
        $this->assertSame($originalQuotaDebt, number_format((float) $updated3->quota_debt, 2, '.', ''));
        $this->assertSame('pending', $updated3->status->value);

        $this->assertSame('150000.00', number_format((float) $updated4->interest_value, 2, '.', ''));
        $this->assertSame('950000.00', number_format((float) $updated4->installment_value, 2, '.', ''));
        $this->assertActivityLogged('exoneracion_intereses');
    }

    public function test_exoneracion_rechaza_cuota_pagada(): void
    {
        $this->postJson("/api/contracts/{$this->contract->id}/refinance", [
            'tipo' => 'exoneracion_intereses',
            'installment_ids' => [$this->installment1->id],
            'reduction_percent' => '50',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.installment_ids.0', 'No se puede exonerar intereses de una cuota ya pagada.');
    }

    public function test_exoneracion_rechaza_porcentaje_invalido(): void
    {
        $this->postJson("/api/contracts/{$this->contract->id}/refinance", [
            'tipo' => 'exoneracion_intereses',
            'installment_ids' => [$this->installment3->id],
            'reduction_percent' => '150',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reduction_percent']);
    }

    public function test_exoneracion_rechaza_si_interes_pagado_supera_el_nuevo_interes(): void
    {
        $this->installment3->update([
            'status' => 'partial',
            'interest_paid' => '180000.00',
            'principal_paid' => '0.00',
            'quota_debt' => '820000.00',
        ]);

        $this->postJson("/api/contracts/{$this->contract->id}/refinance", [
            'tipo' => 'exoneracion_intereses',
            'installment_ids' => [$this->installment3->id],
            'reduction_percent' => '50',
        ])->assertUnprocessable()
            ->assertJsonPath(
                'errors.installment_ids.0',
                'La cuota 3 ya tiene intereses pagados (180000.00) mayores al nuevo interés reducido (100000.00). No se aplicó la exoneración.',
            );
    }

    public function test_socio_gerencia_y_admin_sistema_reciben_403_en_las_cuatro_estrategias(): void
    {
        $payloads = [
            ['tipo' => 'acuerdo_pago', 'extra_amount' => '10000.00', 'months' => 1],
            ['tipo' => 'tiempo_gracia', 'months' => 1],
            ['tipo' => 'refinanciar_saldo', 'new_term_months' => 2, 'new_interest_rate' => '1.00'],
            ['tipo' => 'exoneracion_intereses', 'installment_ids' => [$this->installment3->id], 'reduction_percent' => '10'],
        ];

        $socio = User::factory()->create();
        $this->actingAsRole(RoleName::SOCIO_GERENCIA->value, $socio);

        foreach ($payloads as $payload) {
            $this->postJson("/api/contracts/{$this->contract->id}/refinance", $payload)->assertForbidden();
        }

        $sistema = User::factory()->create();
        $this->actingAsRole(RoleName::ADMIN_SISTEMA->value, $sistema);

        foreach ($payloads as $payload) {
            $this->postJson("/api/contracts/{$this->contract->id}/refinance", $payload)->assertForbidden();
        }
    }

    private function assertActivityLogged(string $tipo): void
    {
        $this->assertDatabaseHas('activity_log', [
            'description' => "Refinanció el contrato mediante {$tipo}",
            'subject_id' => $this->contract->id,
            'causer_id' => $this->admin->id,
        ]);
    }

    private function createInstallment(
        Contract $contract,
        int $number,
        string $dueDate,
        string $status,
        array $overrides = [],
    ): AmortizationInstallment {
        return AmortizationInstallment::query()->create(array_merge([
            'contract_id' => $contract->id,
            'installment_number' => $number,
            'due_date' => $dueDate,
            'installment_value' => '1000000.00',
            'extra_payment' => '0.00',
            'interest_value' => '0.00',
            'principal_value' => '1000000.00',
            'interest_paid' => '0.00',
            'principal_paid' => '0.00',
            'quota_debt' => $status === 'paid' ? '0.00' : '1000000.00',
            'remaining_balance' => '4000000.00',
            'projected_balance' => '4000000.00',
            'status' => $status,
        ], $overrides));
    }
}
