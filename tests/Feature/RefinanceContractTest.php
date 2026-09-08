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
use App\Services\Financial\Refinancing\RefinanceContractService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
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
            'principal_paid' => '2000000.00',
            'interest_value' => '0.00',
            'quota_debt' => '0.00',
            'remaining_balance' => '5000000.00',
        ]);
        $this->installment1 = $this->createInstallment($this->contract, 1, '2027-02-05', 'paid', [
            'installment_value' => '1000000.00',
            'principal_value' => '1000000.00',
            'principal_paid' => '1000000.00',
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

        $response = $this->postRefinance([
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

        $this->postRefinance([
            'tipo' => 'acuerdo_pago',
            'extra_amount' => '250000.00',
            'months' => 3,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.contract.0', 'Solo se pueden refinanciar contratos en estado activo.');
    }

    public function test_acuerdo_pago_rechaza_datos_invalidos(): void
    {
        $this->postRefinance([
            'tipo' => 'acuerdo_pago',
            'extra_amount' => '0',
            'months' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['extra_amount', 'months']);
    }

    public function test_tiempo_gracia_desplaza_todas_las_cuotas_pendientes(): void
    {
        $paidDueDate = $this->installment1->due_date->toDateString();

        $this->postRefinance([
            'tipo' => 'tiempo_gracia',
            'months' => 2,
        ])->assertOk();

        $this->assertSame('2027-01-05', $this->installment0->fresh()->due_date->toDateString());
        $this->assertSame($paidDueDate, $this->installment1->fresh()->due_date->toDateString());
        $this->assertSame('2027-05-05', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('2027-06-05', $this->installment3->fresh()->due_date->toDateString());
        $this->assertSame('2027-07-05', $this->installment4->fresh()->due_date->toDateString());
        $this->assertSame('pending', $this->installment2->fresh()->status->value);
        $this->assertSame('pending', $this->installment3->fresh()->status->value);
        $this->assertSame('paid', $this->installment1->fresh()->status->value);
        $this->assertActivityLogged('tiempo_gracia');
    }

    public function test_tiempo_gracia_quita_el_estado_vencida_si_la_fecha_queda_en_el_futuro(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 12:00:00'));

        $this->installment2->update([
            'due_date' => '2026-03-05',
            'status' => 'overdue',
        ]);
        $this->installment3->update([
            'due_date' => '2026-04-05',
            'status' => 'overdue',
            'principal_paid' => '400000.00',
            'quota_debt' => '600000.00',
        ]);
        $this->installment4->update([
            'due_date' => '2026-08-05',
            'status' => 'overdue',
        ]);

        $this->postRefinance([
            'tipo' => 'tiempo_gracia',
            'months' => 8,
        ])->assertOk();

        $this->assertSame('2026-11-05', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('pending', $this->installment2->fresh()->status->value);

        $this->assertSame('2026-12-05', $this->installment3->fresh()->due_date->toDateString());
        $this->assertSame('partial', $this->installment3->fresh()->status->value);

        $this->assertSame('2027-04-05', $this->installment4->fresh()->due_date->toDateString());
        $this->assertSame('pending', $this->installment4->fresh()->status->value);

        $this->assertSame('paid', $this->installment1->fresh()->status->value);
        $this->assertSame('2027-02-05', $this->installment1->fresh()->due_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_tiempo_gracia_conserva_vencida_si_la_fecha_sigue_en_el_pasado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 12:00:00'));

        $this->installment2->update([
            'due_date' => '2026-01-05',
            'status' => 'overdue',
        ]);

        $this->postRefinance([
            'tipo' => 'tiempo_gracia',
            'months' => 2,
        ])->assertOk();

        $this->assertSame('2026-03-05', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('overdue', $this->installment2->fresh()->status->value);

        Carbon::setTestNow();
    }

    public function test_tiempo_gracia_rechaza_contrato_no_activo(): void
    {
        $this->contract->update(['status' => 'rescindido']);

        $this->postRefinance([
            'tipo' => 'tiempo_gracia',
            'months' => 2,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['contract']);
    }

    public function test_tiempo_gracia_rechaza_meses_invalidos(): void
    {
        $this->postRefinance([
            'tipo' => 'tiempo_gracia',
            'months' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['months']);
    }

    public function test_refinanciar_saldo_regenera_desde_el_ancla_con_cuota_fija_exacta(): void
    {
        $this->postRefinance([
            'tipo' => 'refinanciar_saldo',
            'new_sale_price' => '7000000.00',
            'new_term_months' => 2,
            'new_interest_rate' => '0.00',
            'deferred_interest_action' => 'condonar',
        ])->assertOk();

        $this->contract->refresh();
        $this->assertSame(3, (int) $this->contract->term_months);
        $this->assertSame('0.00', number_format((float) $this->contract->interest_rate, 2, '.', ''));
        $this->assertSame('7000000.00', number_format((float) $this->contract->sale_price, 2, '.', ''));
        $this->assertSame('3000000.00', number_format((float) $this->contract->down_payment_pactada, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $this->contract->deferred_interest_balance, 2, '.', ''));

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

        $this->postRefinance([
            'tipo' => 'refinanciar_saldo',
            'new_sale_price' => '7000000.00',
            'new_term_months' => 2,
            'new_interest_rate' => '1.00',
            'deferred_interest_action' => 'condonar',
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

        $this->postRefinance([
            'tipo' => 'refinanciar_saldo',
            'new_sale_price' => '7000000.00',
            'new_term_months' => 2,
            'new_interest_rate' => '1.00',
            'deferred_interest_action' => 'condonar',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['contract']);
    }

    public function test_refinanciar_saldo_rechaza_datos_invalidos(): void
    {
        $this->postRefinance([
            'tipo' => 'refinanciar_saldo',
            'new_term_months' => 0,
            'new_interest_rate' => '-1',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['new_term_months', 'new_interest_rate', 'new_sale_price']);
    }

    public function test_exoneracion_reduce_interes_con_bcmath_sin_cambiar_capital(): void
    {
        $this->postRefinance([
            'tipo' => 'exoneracion_intereses',
            'installment_ids' => [$this->installment3->id, $this->installment4->id],
            'reduction_percent' => '25',
        ])->assertOk();

        $updated2 = $this->installment2->fresh();
        $updated3 = $this->installment3->fresh();
        $updated4 = $this->installment4->fresh();

        $this->assertSame('150000.00', number_format((float) $updated3->interest_value, 2, '.', ''));
        $this->assertSame('800000.00', number_format((float) $updated3->principal_value, 2, '.', ''));
        $this->assertSame('950000.00', number_format((float) $updated3->installment_value, 2, '.', ''));
        $this->assertSame('950000.00', number_format((float) $updated3->quota_debt, 2, '.', ''));
        $this->assertSame('pending', $updated3->status->value);

        $this->assertSame('150000.00', number_format((float) $updated4->interest_value, 2, '.', ''));
        $this->assertSame('950000.00', number_format((float) $updated4->installment_value, 2, '.', ''));
        $this->assertSame('950000.00', number_format((float) $updated4->quota_debt, 2, '.', ''));

        $this->assertSame('200000.00', number_format((float) $updated2->interest_value, 2, '.', ''));
        $this->assertSame('1000000.00', number_format((float) $updated2->principal_value, 2, '.', ''));
        $this->assertSame('1000000.00', number_format((float) $updated2->installment_value, 2, '.', ''));
        $this->assertActivityLogged('exoneracion_intereses');
    }

    public function test_exoneracion_sanea_remaining_balance_sin_cambiar_principal(): void
    {
        $this->postRefinance([
            'tipo' => 'exoneracion_intereses',
            'installment_ids' => [$this->installment3->id],
            'reduction_percent' => '25',
        ])->assertOk();

        $updated2 = $this->installment2->fresh();
        $updated3 = $this->installment3->fresh();
        $updated4 = $this->installment4->fresh();

        $this->assertSame('3000000.00', number_format((float) $updated2->remaining_balance, 2, '.', ''));
        $this->assertSame('2200000.00', number_format((float) $updated3->remaining_balance, 2, '.', ''));
        $this->assertSame('1400000.00', number_format((float) $updated4->remaining_balance, 2, '.', ''));
        $this->assertSame('1400000.00', number_format((float) $updated4->projected_balance, 2, '.', ''));

        $this->assertSame('800000.00', number_format((float) $updated3->principal_value, 2, '.', ''));
        $this->assertSame('800000.00', number_format((float) $updated4->principal_value, 2, '.', ''));
        $this->assertSame('200000.00', number_format((float) $updated4->interest_value, 2, '.', ''));
        $this->assertSame('1000000.00', number_format((float) $updated4->installment_value, 2, '.', ''));
    }

    public function test_exoneracion_rechaza_cuota_pagada(): void
    {
        $this->postRefinance([
            'tipo' => 'exoneracion_intereses',
            'installment_ids' => [$this->installment1->id],
            'reduction_percent' => '50',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.installment_ids.0', 'No se puede exonerar intereses de una cuota ya pagada.');
    }

    public function test_exoneracion_rechaza_porcentaje_invalido(): void
    {
        $this->postRefinance([
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

        $this->postRefinance([
            'tipo' => 'exoneracion_intereses',
            'installment_ids' => [$this->installment3->id],
            'reduction_percent' => '50',
        ])->assertUnprocessable()
            ->assertJsonPath(
                'errors.installment_ids.0',
                'La cuota 3 ya tiene intereses pagados (180000.00) mayores al nuevo interés reducido (100000.00). No se aplicó la exoneración.',
            );
    }

    public function test_liquidacion_contado_cobra_capital_e_interes_causado_y_condona_el_futuro(): void
    {
        Carbon::setTestNow('2027-04-01');

        try {
            $this->prepareConsistentLiquidationSchedule();
            $this->contract->update(['deferred_interest_balance' => '500000.00']);

            $paid1 = $this->installment1->fresh();

            $this->postRefinance([
                'tipo' => 'liquidacion_contado',
            ])->assertOk();

            $this->contract->refresh();
            $this->assertSame('activo', $this->contract->status->value);
            $this->assertSame('7000000.00', number_format((float) $this->contract->sale_price, 2, '.', ''));
            $this->assertSame(4, (int) $this->contract->term_months);
            $this->assertSame('0.00', number_format((float) $this->contract->interest_rate, 2, '.', ''));
            $this->assertSame('500000.00', number_format((float) $this->contract->deferred_interest_balance, 2, '.', ''));

            $this->assertSame('paid', $paid1->fresh()->status->value);
            $this->assertSame('1000000.00', number_format((float) $paid1->fresh()->principal_paid, 2, '.', ''));

            $updated2 = $this->installment2->fresh();
            $updated3 = $this->installment3->fresh();
            $updated4 = $this->installment4->fresh();

            $this->assertSame('200000.00', number_format((float) $updated2->interest_value, 2, '.', ''));
            $this->assertSame('1700000.00', number_format((float) $updated2->installment_value, 2, '.', ''));
            $this->assertSame('1700000.00', number_format((float) $updated2->quota_debt, 2, '.', ''));
            $this->assertSame('1500000.00', number_format((float) $updated2->principal_value, 2, '.', ''));

            $this->assertSame('0.00', number_format((float) $updated3->interest_value, 2, '.', ''));
            $this->assertSame('1500000.00', number_format((float) $updated3->installment_value, 2, '.', ''));
            $this->assertSame('1500000.00', number_format((float) $updated3->quota_debt, 2, '.', ''));

            $this->assertSame('0.00', number_format((float) $updated4->interest_value, 2, '.', ''));
            $this->assertSame('1000000.00', number_format((float) $updated4->installment_value, 2, '.', ''));
            $this->assertSame('1000000.00', number_format((float) $updated4->quota_debt, 2, '.', ''));

            $debt = bcadd(bcadd(
                number_format((float) $updated2->quota_debt, 2, '.', ''),
                number_format((float) $updated3->quota_debt, 2, '.', ''),
                2
            ), number_format((float) $updated4->quota_debt, 2, '.', ''), 2);
            $this->assertSame('4200000.00', $debt);

            $properties = $this->lastRefinanceActivity()->properties;
            $this->assertSame('4000000.00', $properties['outstanding_capital']);
            $this->assertSame('200000.00', $properties['accrued_unpaid_interest']);
            $this->assertSame('400000.00', $properties['unaccrued_forgiven_interest']);
            $this->assertSame('4200000.00', $properties['amount_to_close']);
            $this->assertSame('500000.00', $properties['deferred_interest_balance']);
            $this->assertActivityLogged('liquidacion_contado');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_liquidacion_contado_toma_el_capital_del_cronograma_y_no_de_sale_price(): void
    {
        Carbon::setTestNow('2027-04-01');

        try {
            $this->prepareConsistentLiquidationSchedule();

            // Cuota con abono extra incorporado al capital: el cronograma deja de
            // sumar sale_price, como pasa en varios contratos de San Miguel.
            $this->installment1->update([
                'principal_value' => '1700000.00',
                'principal_paid' => '1700000.00',
                'installment_value' => '1700000.00',
                'extra_payment' => '700000.00',
            ]);

            $this->postRefinance([
                'tipo' => 'liquidacion_contado',
            ])->assertOk();

            $properties = $this->lastRefinanceActivity()->properties;

            // sale_price − capital pagado daría 3.300.000, que no es lo que queda por cobrar.
            $this->assertSame('4000000.00', $properties['outstanding_capital']);
            $this->assertSame('4200000.00', $properties['amount_to_close']);

            $debt = '0.00';
            foreach ($this->contract->amortizationInstallments()->get() as $row) {
                $debt = bcadd($debt, number_format((float) $row->quota_debt, 2, '.', ''), 2);
            }

            $this->assertSame($properties['amount_to_close'], $debt);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_liquidacion_contado_rechaza_si_no_hay_saldo(): void
    {
        $this->installment2->update(['status' => 'paid', 'quota_debt' => '0.00', 'principal_paid' => '1000000.00']);
        $this->installment3->update(['status' => 'paid', 'quota_debt' => '0.00', 'principal_paid' => '800000.00']);
        $this->installment4->update(['status' => 'paid', 'quota_debt' => '0.00', 'principal_paid' => '800000.00']);

        $this->postRefinance([
            'tipo' => 'liquidacion_contado',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.tipo.0', 'No hay saldo pendiente para liquidar de contado.');

        $this->assertSame(0, $this->refinanceLogCount());
    }

    public function test_socio_gerencia_y_admin_sistema_reciben_403_en_las_cinco_estrategias(): void
    {
        $payloads = [
            ['tipo' => 'acuerdo_pago', 'extra_amount' => '10000.00', 'months' => 1],
            ['tipo' => 'tiempo_gracia', 'months' => 1],
            ['tipo' => 'refinanciar_saldo', 'new_sale_price' => '7000000.00', 'new_term_months' => 2, 'new_interest_rate' => '1.00', 'deferred_interest_action' => 'condonar'],
            ['tipo' => 'exoneracion_intereses', 'installment_ids' => [$this->installment3->id], 'reduction_percent' => '10'],
            ['tipo' => 'liquidacion_contado'],
        ];

        $socio = User::factory()->create();
        $this->actingAsRole(RoleName::SOCIO_GERENCIA->value, $socio);

        foreach ($payloads as $payload) {
            $this->postRefinance($payload)->assertForbidden();
        }

        $sistema = User::factory()->create();
        $this->actingAsRole(RoleName::ADMIN_SISTEMA->value, $sistema);

        foreach ($payloads as $payload) {
            $this->postRefinance($payload)->assertForbidden();
        }
    }

    public function test_las_cinco_estrategias_exigen_motivo(): void
    {
        $payloads = [
            ['tipo' => 'acuerdo_pago', 'extra_amount' => '250000.00', 'months' => 3],
            ['tipo' => 'tiempo_gracia', 'months' => 2],
            ['tipo' => 'refinanciar_saldo', 'new_sale_price' => '7000000.00', 'new_term_months' => 2, 'new_interest_rate' => '1.00', 'deferred_interest_action' => 'condonar'],
            ['tipo' => 'exoneracion_intereses', 'installment_ids' => [$this->installment3->id], 'reduction_percent' => '10'],
            ['tipo' => 'liquidacion_contado'],
        ];

        foreach ($payloads as $payload) {
            $this->postJson("/api/contracts/{$this->contract->id}/refinance", $payload)
                ->assertUnprocessable()
                ->assertJsonPath('errors.motivo.0', 'El motivo de la refinanciación es obligatorio.');
        }

        $this->assertSame(0, $this->refinanceLogCount());
    }

    public function test_motivo_demasiado_corto_es_rechazado(): void
    {
        $this->postRefinance([
            'tipo' => 'tiempo_gracia',
            'months' => 2,
            'motivo' => 'porque',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['motivo']);
    }

    public function test_el_motivo_queda_guardado_en_la_bitacora(): void
    {
        $this->postRefinance([
            'tipo' => 'tiempo_gracia',
            'months' => 2,
            'motivo' => 'El cliente perdió el empleo; prórroga aprobada en comité.',
        ])->assertOk();

        $properties = $this->lastRefinanceActivity()->properties;

        $this->assertSame('El cliente perdió el empleo; prórroga aprobada en comité.', $properties['motivo']);
        $this->assertArrayNotHasKey('motivo', $properties['params']);
    }

    public function test_la_bitacora_guarda_copia_de_las_cuotas_que_se_borran(): void
    {
        $this->postRefinance([
            'tipo' => 'refinanciar_saldo',
            'new_sale_price' => '7000000.00',
            'new_term_months' => 2,
            'new_interest_rate' => '0.00',
            'deferred_interest_action' => 'condonar',
        ])->assertOk();

        $snapshot = $this->lastRefinanceActivity()->properties['installments_before'];

        $this->assertCount(3, $snapshot);
        $this->assertSame([2, 3, 4], array_column($snapshot, 'installment_number'));
        $this->assertSame('2027-03-05', $snapshot[0]['due_date']);
        $this->assertSame('1000000.00', $snapshot[0]['installment_value']);
        $this->assertSame('200000.00', $snapshot[0]['interest_value']);
        $this->assertSame('1000000.00', $snapshot[0]['principal_value']);
        $this->assertSame('3000000.00', $snapshot[0]['remaining_balance']);
        $this->assertSame('0.00', $snapshot[0]['principal_paid']);
        $this->assertSame('overdue', $snapshot[0]['status']);
    }

    public function test_la_bitacora_guarda_copia_de_las_cuotas_exoneradas_y_ninguna_en_acuerdo_de_pago(): void
    {
        $this->postRefinance([
            'tipo' => 'exoneracion_intereses',
            'installment_ids' => [$this->installment4->id],
            'reduction_percent' => '25',
        ])->assertOk();

        $snapshot = $this->lastRefinanceActivity()->properties['installments_before'];
        $this->assertSame([2, 3, 4], array_column($snapshot, 'installment_number'));
        $this->assertSame('200000.00', $snapshot[2]['interest_value']);

        $this->postRefinance([
            'tipo' => 'acuerdo_pago',
            'extra_amount' => '250000.00',
            'months' => 2,
        ])->assertOk();

        $this->assertSame([], $this->lastRefinanceActivity()->properties['installments_before']);
    }

    public function test_refinanciar_saldo_se_bloquea_si_la_cuota_ancla_tiene_abono_parcial(): void
    {
        $this->installment2->update([
            'status' => 'partial',
            'principal_paid' => '400000.00',
            'quota_debt' => '600000.00',
        ]);

        $this->postRefinance([
            'tipo' => 'refinanciar_saldo',
            'new_sale_price' => '7000000.00',
            'new_term_months' => 2,
            'new_interest_rate' => '0.00',
            'deferred_interest_action' => 'condonar',
        ])->assertUnprocessable()
            ->assertJsonPath(
                'errors.anchor_installment.0',
                'La cuota 2, donde arranca la refinanciación, tiene un abono parcial de 400.000,00. '
                .'Debe liquidarla o cerrarla antes de refinanciar.',
            );

        $this->assertSame('400000.00', number_format((float) $this->installment2->fresh()->principal_paid, 2, '.', ''));
        $this->assertSame(4, $this->contract->installments()->where('installment_number', '>', 0)->count());
        $this->assertSame(0, $this->refinanceLogCount());
    }

    public function test_refinanciar_saldo_bloquea_si_la_cuota_inicial_sigue_abierta(): void
    {
        $this->installment0->update([
            'status' => 'partial',
            'principal_paid' => '1500000.00',
            'quota_debt' => '500000.00',
        ]);

        $this->postRefinance([
            'tipo' => 'refinanciar_saldo',
            'new_sale_price' => '7000000.00',
            'new_term_months' => 2,
            'new_interest_rate' => '0.00',
            'deferred_interest_action' => 'condonar',
        ])->assertUnprocessable()
            ->assertJsonPath(
                'errors.initial_installment.0',
                'La cuota inicial aún tiene un saldo de 500.000,00. '
                .'Debe liquidarla o cerrarla antes de refinanciar el saldo.',
            );

        $this->assertSame(0, $this->refinanceLogCount());
    }

    public function test_refinanciar_saldo_nueva_inicial_solo_suma_principal_pagado(): void
    {
        $this->installment3->update([
            'interest_paid' => '50000.00',
            'principal_paid' => '0.00',
            'quota_debt' => '950000.00',
            'status' => 'partial',
        ]);

        // Interest paid must NOT enter the new down payment.
        $this->postRefinance([
            'tipo' => 'refinanciar_saldo',
            'new_sale_price' => '9000000.00',
            'new_term_months' => 2,
            'new_interest_rate' => '0.00',
            'deferred_interest_action' => 'condonar',
        ])->assertOk();

        $this->contract->refresh();
        $this->assertSame('3000000.00', number_format((float) $this->contract->down_payment_pactada, 2, '.', ''));
        $this->assertSame('9000000.00', number_format((float) $this->contract->sale_price, 2, '.', ''));

        $future = $this->contract->installments()
            ->where('installment_number', '>=', 2)
            ->orderBy('installment_number')
            ->get();

        // Capital = 9M − 3M = 6M → 2 cuotas de 3M a tasa 0.
        $this->assertCount(2, $future);
        $this->assertSame('3000000.00', number_format((float) $future[0]->installment_value, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $future[0]->interest_value, 2, '.', ''));
        $this->assertSame('3000000.00', number_format((float) $future[1]->installment_value, 2, '.', ''));
    }

    public function test_refinanciar_saldo_interes_impago_nunca_entra_al_capital(): void
    {
        $calculator = app(AmortizationCalculationService::class);
        // Accrued unpaid interest on rows 2-4 = 600000; capital must stay 4M.
        $quota = $calculator->calculateFixedQuota('4000000.00', '0.00', 2);

        $this->postRefinance([
            'tipo' => 'refinanciar_saldo',
            'new_sale_price' => '7000000.00',
            'new_term_months' => 2,
            'new_interest_rate' => '0.00',
            'deferred_interest_action' => 'condonar',
        ])->assertOk();

        $future = $this->contract->installments()
            ->where('installment_number', '>=', 2)
            ->orderBy('installment_number')
            ->get();

        $this->assertSame($quota, number_format((float) $future[0]->installment_value, 2, '.', ''));
        $this->assertSame('2000000.00', number_format((float) $future[0]->principal_value, 2, '.', ''));
        $this->assertNotSame('2300000.00', number_format((float) $future[0]->installment_value, 2, '.', ''));
    }

    public function test_refinanciar_saldo_cobrar_aparte_acumula_diferido_y_condonar_no(): void
    {
        $this->contract->update(['deferred_interest_balance' => '100000.00']);

        $this->postRefinance([
            'tipo' => 'refinanciar_saldo',
            'new_sale_price' => '7000000.00',
            'new_term_months' => 2,
            'new_interest_rate' => '0.00',
            'deferred_interest_action' => 'cobrar_aparte',
        ])->assertOk();

        // Accrued on 2+3+4 = 600000 + previous 100000.
        $this->assertSame('700000.00', number_format((float) $this->contract->fresh()->deferred_interest_balance, 2, '.', ''));

        $activity = $this->lastRefinanceActivity();
        $this->assertSame('600000.00', $activity->properties['accrued_unpaid_interest']);
        $this->assertSame('cobrar_aparte', $activity->properties['deferred_interest_action']);
        $this->assertSame('3000000.00', $activity->properties['new_down_payment']);
        $this->assertSame('4000000.00', $activity->properties['new_principal']);
    }

    public function test_refinanciar_saldo_exige_decision_si_hay_interes_causado(): void
    {
        $this->postRefinance([
            'tipo' => 'refinanciar_saldo',
            'new_sale_price' => '7000000.00',
            'new_term_months' => 2,
            'new_interest_rate' => '0.00',
        ])->assertUnprocessable()
            ->assertJsonPath(
                'errors.deferred_interest_action.0',
                'Debe indicar si el interés causado no pagado se cobra aparte o se condona.',
            );
    }

    public function test_refinanciar_saldo_no_toca_cuotas_pagadas_ni_crea_transacciones(): void
    {
        $paidId = $this->installment1->id;
        $paidPrincipal = number_format((float) $this->installment1->principal_paid, 2, '.', '');
        $txCount = \App\Models\Transaction::query()->where('contract_id', $this->contract->id)->count();

        $this->postRefinance([
            'tipo' => 'refinanciar_saldo',
            'new_sale_price' => '8000000.00',
            'new_term_months' => 3,
            'new_interest_rate' => '0.00',
            'deferred_interest_action' => 'condonar',
        ])->assertOk();

        $paid = $this->installment1->fresh();
        $this->assertNotNull($paid);
        $this->assertSame($paidId, $paid->id);
        $this->assertSame('paid', $paid->status->value);
        $this->assertSame($paidPrincipal, number_format((float) $paid->principal_paid, 2, '.', ''));
        $this->assertSame(
            $txCount,
            \App\Models\Transaction::query()->where('contract_id', $this->contract->id)->count(),
        );
        $this->assertSame('0.00', number_format((float) $this->contract->fresh()->deferred_interest_balance, 2, '.', ''));
    }

    public function test_misma_clave_de_idempotencia_no_aplica_el_cambio_dos_veces(): void
    {
        $payload = [
            'tipo' => 'acuerdo_pago',
            'extra_amount' => '250000.00',
            'months' => 3,
            'idempotency_key' => '6f1b8c2e-7d4a-4f31-9c58-0a2b3c4d5e6f',
        ];

        $this->postRefinance($payload)
            ->assertOk()
            ->assertJsonPath('message', 'Contrato refinanciado exitosamente.');

        $this->postRefinance($payload)
            ->assertOk()
            ->assertJsonPath('message', 'Esta refinanciación ya había sido aplicada; no se repitió el cambio.');

        $this->assertSame(3, ContractPaymentPromise::query()->where('contract_id', $this->contract->id)->count());
        $this->assertSame(1, $this->refinanceLogCount());
    }

    public function test_claves_de_idempotencia_distintas_aplican_dos_veces(): void
    {
        $this->postRefinance([
            'tipo' => 'acuerdo_pago',
            'extra_amount' => '250000.00',
            'months' => 2,
            'idempotency_key' => '6f1b8c2e-7d4a-4f31-9c58-0a2b3c4d5e6f',
        ])->assertOk();

        $this->postRefinance([
            'tipo' => 'acuerdo_pago',
            'extra_amount' => '250000.00',
            'months' => 2,
            'idempotency_key' => '11111111-2222-4333-8444-555555555555',
        ])->assertOk();

        $this->assertSame(4, ContractPaymentPromise::query()->where('contract_id', $this->contract->id)->count());
        $this->assertSame(2, $this->refinanceLogCount());
    }

    public function test_una_refinanciacion_fallida_libera_la_clave_de_idempotencia(): void
    {
        $payload = [
            'tipo' => 'tiempo_gracia',
            'months' => 2,
            'idempotency_key' => '6f1b8c2e-7d4a-4f31-9c58-0a2b3c4d5e6f',
        ];

        $this->contract->installments()->update(['status' => 'paid']);
        $this->postRefinance($payload)->assertUnprocessable();

        $this->contract->installments()->where('installment_number', '>=', 2)->update(['status' => 'pending']);
        $this->postRefinance($payload)
            ->assertOk()
            ->assertJsonPath('message', 'Contrato refinanciado exitosamente.');
    }

    public function test_administrador_ve_solo_refinanciaciones_en_la_bitacora_del_contrato(): void
    {
        activity()->performedOn($this->contract)->log('Actualizó el contrato');

        $this->postRefinance([
            'tipo' => 'tiempo_gracia',
            'months' => 1,
        ])->assertOk();

        $response = $this->getJson("/api/activity?subject_type=contract&subject_id={$this->contract->id}")
            ->assertOk();

        $entries = $response->json('data.data');
        $this->assertCount(1, $entries);
        $this->assertSame('Refinanció el contrato mediante tiempo_gracia', $entries[0]['description']);
    }

    public function test_administrador_no_puede_ver_la_bitacora_del_cliente(): void
    {
        $this->getJson("/api/activity?subject_type=customer&subject_id={$this->contract->customer_id}")
            ->assertForbidden();
    }

    public function test_socio_gerencia_ve_toda_la_bitacora_del_contrato(): void
    {
        activity()->performedOn($this->contract)->log('Actualizó el contrato');

        $this->postRefinance([
            'tipo' => 'tiempo_gracia',
            'months' => 1,
        ])->assertOk();

        $this->actingAsRole(RoleName::SOCIO_GERENCIA->value, User::factory()->create());

        $entries = $this->getJson("/api/activity?subject_type=contract&subject_id={$this->contract->id}")
            ->assertOk()
            ->json('data.data');

        $descriptions = array_column($entries, 'description');
        $this->assertContains('Refinanció el contrato mediante tiempo_gracia', $descriptions);
        $this->assertContains('Actualizó el contrato', $descriptions);
    }

    private function postRefinance(array $payload): TestResponse
    {
        return $this->postJson("/api/contracts/{$this->contract->id}/refinance", array_merge([
            'motivo' => 'Otrosí firmado con el cliente el 2 de septiembre.',
        ], $payload));
    }

    private function assertActivityLogged(string $tipo): void
    {
        $this->assertDatabaseHas('activity_log', [
            'log_name' => RefinanceContractService::LOG_NAME,
            'description' => "Refinanció el contrato mediante {$tipo}",
            'subject_id' => $this->contract->id,
            'causer_id' => $this->admin->id,
        ]);
    }

    private function refinanceLogCount(): int
    {
        return Activity::query()
            ->where('log_name', RefinanceContractService::LOG_NAME)
            ->where('subject_id', $this->contract->id)
            ->count();
    }

    private function lastRefinanceActivity(): Activity
    {
        return Activity::query()
            ->where('log_name', RefinanceContractService::LOG_NAME)
            ->where('subject_id', $this->contract->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function prepareConsistentLiquidationSchedule(): void
    {
        $this->installment2->update([
            'principal_value' => '1500000.00',
            'interest_value' => '200000.00',
            'installment_value' => '1700000.00',
            'quota_debt' => '1700000.00',
            'remaining_balance' => '2500000.00',
            'projected_balance' => '2500000.00',
        ]);
        $this->installment3->update([
            'principal_value' => '1500000.00',
            'interest_value' => '200000.00',
            'installment_value' => '1700000.00',
            'quota_debt' => '1700000.00',
            'remaining_balance' => '1000000.00',
            'projected_balance' => '1000000.00',
        ]);
        $this->installment4->update([
            'principal_value' => '1000000.00',
            'interest_value' => '200000.00',
            'installment_value' => '1200000.00',
            'quota_debt' => '1200000.00',
            'remaining_balance' => '0.00',
            'projected_balance' => '0.00',
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
