<?php

namespace Tests\Feature;

use App\Enums\AmortizationStatus;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\ContractPaymentPromise;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class UpdateInstallmentDueDateTest extends TestCase
{
    use RefreshDatabase;

    private Contract $contract;

    private Contract $otherContract;

    private AmortizationInstallment $installment0;

    private AmortizationInstallment $installment1;

    private AmortizationInstallment $installment2;

    private AmortizationInstallment $installment3;

    private AmortizationInstallment $paidInstallment;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->actingAsRole(RoleName::ADMINISTRADOR->value);

        $project = Project::query()->create([
            'name' => 'Proyecto Editar Vencimiento',
            'description' => 'Fixture pruebas edición due_date',
            'location' => 'Bogota',
            'status' => 'active',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $customer = Customer::factory()->create();
        $lot = Lot::factory()->create(['project_id' => $project->id]);
        $otherLot = Lot::factory()->create(['project_id' => $project->id]);

        $this->contract = Contract::factory()->create([
            'customer_id' => $customer->id,
            'lot_id' => $lot->id,
            'sale_price' => 7000000,
            'down_payment_pactada' => 2000000,
            'term_months' => 5,
            'interest_rate' => 0,
            'status' => 'activo',
            'start_date' => '2027-01-01',
            'initial_payment_date' => '2027-01-01',
            'first_installment_date' => '2027-02-05',
            'regular_payment_start_date' => '2027-02-05',
            'preventa_installments_count' => 0,
            'is_custom_plan' => false,
        ]);

        $this->otherContract = Contract::factory()->create([
            'customer_id' => $customer->id,
            'lot_id' => $otherLot->id,
            'sale_price' => 8000000,
            'down_payment_pactada' => 1000000,
            'term_months' => 4,
            'interest_rate' => 0,
            'status' => 'activo',
            'start_date' => '2027-01-01',
            'initial_payment_date' => '2027-01-01',
            'first_installment_date' => '2027-02-05',
            'regular_payment_start_date' => '2027-02-05',
            'preventa_installments_count' => 0,
        ]);

        $this->installment0 = $this->createInstallment($this->contract, 0, '2027-01-05', 'pending');
        $this->installment1 = $this->createInstallment($this->contract, 1, '2027-03-05', 'pending', '2027-03-06');
        $this->installment2 = $this->createInstallment($this->contract, 2, '2027-04-05', 'overdue');
        $this->installment3 = $this->createInstallment($this->contract, 3, '2027-05-05', 'pending');
        $this->paidInstallment = $this->createInstallment($this->contract, 4, '2027-06-05', 'paid', '2027-06-01');
    }

    public function test_modo_a_acepta_fecha_estrictamente_entre_vecinas(): void
    {
        $response = $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-04-20', 'mode' => 'single', 'confirm' => true],
        );

        $response->assertOk()
            ->assertJsonPath('data.plan.mode', 'single')
            ->assertJsonPath('data.plan.affected_count', 1);

        $this->assertSame('2027-04-20', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('2027-03-05', $this->installment1->fresh()->due_date->toDateString());
        $this->assertSame('2027-05-05', $this->installment3->fresh()->due_date->toDateString());
        $this->assertFinancialsUntouched($this->installment2);
        $this->assertSame('2027-02-05', $this->contract->fresh()->first_installment_date->toDateString());
    }

    public function test_modo_a_rechaza_fecha_menor_o_igual_a_la_cuota_anterior(): void
    {
        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-03-01', 'mode' => 'single', 'confirm' => true],
        )->assertUnprocessable();

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-03-05', 'mode' => 'single', 'confirm' => true],
        )->assertUnprocessable()
            ->assertJsonFragment(['due_date' => ['La fecha debe estar estrictamente entre 05/03/2027 y 05/05/2027.']]);

        $this->assertSame('2027-04-05', $this->installment2->fresh()->due_date->toDateString());
    }

    public function test_modo_a_rechaza_fecha_mayor_o_igual_a_la_cuota_siguiente(): void
    {
        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-05-05', 'mode' => 'single', 'confirm' => true],
        )->assertUnprocessable();

        $this->assertSame('2027-04-05', $this->installment2->fresh()->due_date->toDateString());
    }

    public function test_modo_a_en_la_ultima_cuota_no_tiene_techo_y_permite_pagadas(): void
    {
        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->paidInstallment->id}/due-date",
            ['due_date' => '2027-07-20', 'mode' => 'single', 'confirm' => true],
        )->assertOk();

        $paid = $this->paidInstallment->fresh();
        $this->assertSame('2027-07-20', $paid->due_date->toDateString());
        $this->assertSame('2027-06-01', Carbon::parse((string) $paid->payment_date)->toDateString());
        $this->assertSame(AmortizationStatus::PAID, $paid->status);
        $this->assertSame('2027-05-05', $this->installment3->fresh()->due_date->toDateString());
    }

    public function test_modo_b_desde_cuota_1_actualiza_ancla_y_no_toca_cuota_0(): void
    {
        $snapshot0 = $this->financialSnapshot($this->installment0);

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment1->id}/due-date",
            ['due_date' => '2027-02-15', 'mode' => 'cascade', 'confirm' => true],
        )->assertOk()
            ->assertJsonPath('data.plan.updates_contract_anchor', true)
            ->assertJsonPath('data.plan.affected_count', 4);

        $this->assertSame('2027-01-05', $this->installment0->fresh()->due_date->toDateString());
        $this->assertSame($snapshot0, $this->financialSnapshot($this->installment0->fresh()));
        $this->assertSame('2027-02-15', $this->installment1->fresh()->due_date->toDateString());
        $this->assertSame('2027-03-15', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('2027-04-15', $this->installment3->fresh()->due_date->toDateString());
        $this->assertSame('2027-05-15', $this->paidInstallment->fresh()->due_date->toDateString());
        $this->assertSame('2027-03-06', Carbon::parse((string) $this->installment1->fresh()->payment_date)->toDateString());

        $contract = $this->contract->fresh();
        $this->assertSame('2027-02-15', $contract->first_installment_date->toDateString());
        $this->assertSame('2027-02-15', $contract->regular_payment_start_date->toDateString());
        $this->assertSame('2027-01-01', $contract->start_date->toDateString());
        $this->assertSame('2027-01-01', $contract->initial_payment_date->toDateString());
    }

    public function test_modo_b_desde_cuota_intermedia_no_actualiza_ancla_ni_cuotas_anteriores(): void
    {
        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-04-18', 'mode' => 'cascade', 'confirm' => true],
        )->assertOk()
            ->assertJsonPath('data.plan.updates_contract_anchor', false);

        $this->assertSame('2027-01-05', $this->installment0->fresh()->due_date->toDateString());
        $this->assertSame('2027-03-05', $this->installment1->fresh()->due_date->toDateString());
        $this->assertSame('2027-04-18', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('2027-05-18', $this->installment3->fresh()->due_date->toDateString());
        $this->assertSame('2027-06-18', $this->paidInstallment->fresh()->due_date->toDateString());
        $this->assertSame('2027-02-05', $this->contract->fresh()->first_installment_date->toDateString());
    }

    public function test_modo_b_exige_fecha_posterior_a_la_cuota_anterior_sin_techo(): void
    {
        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-03-05', 'mode' => 'cascade', 'confirm' => true],
        )->assertUnprocessable();

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-08-01', 'mode' => 'cascade', 'confirm' => true],
        )->assertOk();

        $this->assertSame('2027-08-01', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('2027-09-01', $this->installment3->fresh()->due_date->toDateString());
        $this->assertSame('2027-10-01', $this->paidInstallment->fresh()->due_date->toDateString());
    }

    public function test_fin_de_mes_usa_add_months_no_overflow_desde_el_origen(): void
    {
        $this->installment0->update(['due_date' => '2025-12-31']);
        $this->installment1->update(['due_date' => '2026-01-31']);
        $this->installment2->update(['due_date' => '2026-02-28']);
        $this->installment3->update(['due_date' => '2026-03-31']);
        $this->paidInstallment->update(['due_date' => '2026-04-30']);

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment1->id}/due-date",
            ['due_date' => '2026-01-31', 'mode' => 'cascade', 'confirm' => true],
        )->assertOk();

        $this->assertSame('2026-01-31', $this->installment1->fresh()->due_date->toDateString());
        $this->assertSame('2026-02-28', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('2026-03-31', $this->installment3->fresh()->due_date->toDateString());
        $this->assertSame('2026-04-30', $this->paidInstallment->fresh()->due_date->toDateString());
        $this->assertNotSame('2026-03-03', $this->installment2->fresh()->due_date->toDateString());
    }

    public function test_cascada_fin_de_mes_recorre_un_anio_completo(): void
    {
        $this->installment0->update(['due_date' => '2025-01-05']);
        $extra = [];
        for ($number = 5; $number <= 12; $number++) {
            $extra[$number] = $this->createInstallment(
                $this->contract,
                $number,
                sprintf('2028-%02d-05', min($number, 12)),
                'pending',
            );
        }

        $expected = [
            1 => '2025-04-30',
            2 => '2025-05-31',
            3 => '2025-06-30',
            4 => '2025-07-31',
            5 => '2025-08-31',
            6 => '2025-09-30',
            7 => '2025-10-31',
            8 => '2025-11-30',
            9 => '2025-12-31',
            10 => '2026-01-31',
            11 => '2026-02-28',
            12 => '2026-03-31',
        ];

        $preview = $this->postJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment1->id}/due-date/preview",
            ['due_date' => '2025-04-30', 'mode' => 'cascade', 'cadence' => 'month_end'],
        )->assertOk()
            ->assertJsonPath('data.cadence', 'month_end')
            ->assertJsonPath('data.affected_count', 12)
            ->json('data.changes');

        foreach ($expected as $number => $date) {
            $this->assertSame($date, $preview[$number - 1]['due_date_after'], 'cuota '.$number);
        }

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment1->id}/due-date",
            ['due_date' => '2025-04-30', 'mode' => 'cascade', 'cadence' => 'month_end', 'confirm' => true],
        )->assertOk()
            ->assertJsonPath('data.plan.cadence', 'month_end');

        $this->assertSame('2025-04-30', $this->installment1->fresh()->due_date->toDateString());
        $this->assertSame('2025-05-31', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('2026-02-28', $extra[11]->fresh()->due_date->toDateString());
        $this->assertSame('2026-03-31', $extra[12]->fresh()->due_date->toDateString());
        $this->assertFinancialsUntouched($this->installment2);

        $entry = Activity::query()
            ->where('description', 'like', '%fin de mes%')
            ->latest('id')
            ->first();
        $this->assertNotNull($entry);
        $this->assertSame('month_end', $entry->properties['cadence']);
    }

    public function test_cascada_fin_de_mes_desde_28_febrero_no_bisiesto_cae_en_31_marzo(): void
    {
        $this->installment0->update(['due_date' => '2025-01-05']);
        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment1->id}/due-date",
            ['due_date' => '2025-02-28', 'mode' => 'cascade', 'cadence' => 'month_end', 'confirm' => true],
        )->assertOk();

        $this->assertSame('2025-02-28', $this->installment1->fresh()->due_date->toDateString());
        $this->assertSame('2025-03-31', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('2025-04-30', $this->installment3->fresh()->due_date->toDateString());
        $this->assertSame('2025-05-31', $this->paidInstallment->fresh()->due_date->toDateString());
        $this->assertNotSame('2025-03-28', $this->installment2->fresh()->due_date->toDateString());
    }

    public function test_cascada_fin_de_mes_respeta_29_febrero_en_anio_bisiesto(): void
    {
        $this->installment0->update(['due_date' => '2023-12-05']);
        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment1->id}/due-date",
            ['due_date' => '2024-01-31', 'mode' => 'cascade', 'cadence' => 'month_end', 'confirm' => true],
        )->assertOk();

        $this->assertSame('2024-01-31', $this->installment1->fresh()->due_date->toDateString());
        $this->assertSame('2024-02-29', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('2024-03-31', $this->installment3->fresh()->due_date->toDateString());
        $this->assertSame('2024-04-30', $this->paidInstallment->fresh()->due_date->toDateString());
    }

    public function test_cascada_sin_cadence_sigue_siendo_mismo_dia_del_mes(): void
    {
        $this->installment0->update(['due_date' => '2025-12-31']);
        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment1->id}/due-date",
            ['due_date' => '2026-01-31', 'mode' => 'cascade', 'confirm' => true],
        )->assertOk()
            ->assertJsonPath('data.plan.cadence', 'same_day');

        $this->assertSame('2026-01-31', $this->installment1->fresh()->due_date->toDateString());
        $this->assertSame('2026-02-28', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('2026-03-31', $this->installment3->fresh()->due_date->toDateString());
        $this->assertSame('2026-04-30', $this->paidInstallment->fresh()->due_date->toDateString());
    }

    public function test_modo_single_ignora_cadence_fin_de_mes(): void
    {
        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-04-20', 'mode' => 'single', 'cadence' => 'month_end', 'confirm' => true],
        )->assertOk()
            ->assertJsonPath('data.plan.cadence', 'same_day')
            ->assertJsonPath('data.plan.affected_count', 1);

        $this->assertSame('2027-04-20', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('2027-03-05', $this->installment1->fresh()->due_date->toDateString());
        $this->assertSame('2027-05-05', $this->installment3->fresh()->due_date->toDateString());
    }

    public function test_plan_custom_recadencia_promesas_con_fin_de_mes(): void
    {
        $this->contract->update(['is_custom_plan' => true]);
        $this->installment0->update(['due_date' => '2025-01-05']);

        $p1 = $this->createPromise(1, '2027-03-05');
        $p2 = $this->createPromise(2, '2027-04-05');
        $p3 = $this->createPromise(3, '2027-05-05');
        $p4 = $this->createPromise(4, '2027-06-05');

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment1->id}/due-date",
            ['due_date' => '2025-04-30', 'mode' => 'cascade', 'cadence' => 'month_end', 'confirm' => true],
        )->assertOk();

        $this->assertSame('2025-04-30', $p1->fresh()->expected_date->toDateString());
        $this->assertSame('2025-05-31', $p2->fresh()->expected_date->toDateString());
        $this->assertSame('2025-06-30', $p3->fresh()->expected_date->toDateString());
        $this->assertSame('2025-07-31', $p4->fresh()->expected_date->toDateString());
    }

    public function test_preview_no_persiste_y_muestra_muestra_de_filas(): void
    {
        $response = $this->postJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment1->id}/due-date/preview",
            ['due_date' => '2027-02-20', 'mode' => 'cascade'],
        );

        $response->assertOk()
            ->assertJsonPath('data.affected_count', 4)
            ->assertJsonPath('data.preview.0.due_date_after', '2027-02-20');

        $this->assertSame('2027-03-05', $this->installment1->fresh()->due_date->toDateString());
        $this->assertSame('2027-02-05', $this->contract->fresh()->first_installment_date->toDateString());
    }

    public function test_requiere_confirmacion_para_persistir(): void
    {
        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-04-20', 'mode' => 'single'],
        )->assertUnprocessable();
    }

    public function test_plan_custom_recadencia_promesas_en_cascada(): void
    {
        $this->contract->update(['is_custom_plan' => true]);

        $p1 = $this->createPromise(1, '2027-03-05');
        $p2 = $this->createPromise(2, '2027-04-05');
        $p3 = $this->createPromise(3, '2027-05-05');
        $p4 = $this->createPromise(4, '2027-06-05');

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-04-18', 'mode' => 'cascade', 'confirm' => true],
        )->assertOk()
            ->assertJsonPath('data.plan.shifts_promises', true)
            ->assertJsonPath('data.plan.promises_affected_count', 3);

        $this->assertSame('2027-03-05', $p1->fresh()->expected_date->toDateString());
        $this->assertSame('2027-04-18', $p2->fresh()->expected_date->toDateString());
        $this->assertSame('2027-05-18', $p3->fresh()->expected_date->toDateString());
        $this->assertSame('2027-06-18', $p4->fresh()->expected_date->toDateString());
        $this->assertSame('2027-03-05', $this->installment1->fresh()->due_date->toDateString());
    }

    public function test_no_modifica_transactions(): void
    {
        $transaction = Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::REGULAR_PAYMENT->value,
            'amount' => '1000000.00',
            'transaction_date' => '2027-03-06',
            'payment_method' => 'cash',
            'notes' => 'recibo original',
        ]);

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment1->id}/due-date",
            ['due_date' => '2027-02-20', 'mode' => 'cascade', 'confirm' => true],
        )->assertOk();

        $fresh = $transaction->fresh();
        $this->assertSame('2027-03-06', $fresh->transaction_date->toDateString());
        $this->assertSame('recibo original', $fresh->notes);
        $this->assertSame('1000000.00', (string) $fresh->amount);
    }

    public function test_registra_activity_log_con_modo_y_conteo(): void
    {
        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment1->id}/due-date",
            ['due_date' => '2027-02-15', 'mode' => 'cascade', 'confirm' => true],
        )->assertOk();

        $entry = Activity::query()
            ->where('description', 'like', 'Ajustó vencimientos en cascada%')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('cascade', $entry->properties['mode']);
        $this->assertSame('same_day', $entry->properties['cadence']);
        $this->assertSame(1, (int) $entry->properties['installment_number']);
        $this->assertSame(4, (int) $entry->properties['affected_count']);
    }

    public function test_rechaza_si_installment_number_es_0(): void
    {
        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment0->id}/due-date",
            ['due_date' => '2027-01-10', 'mode' => 'single', 'confirm' => true],
        )->assertUnprocessable()
            ->assertJsonPath('errors.installment.0', 'La cuota inicial no se puede modificar desde este flujo.');
    }

    public function test_responde_404_si_la_cuota_no_pertenece_al_contrato(): void
    {
        $foreignInstallment = $this->createInstallment($this->otherContract, 1, '2027-02-10', 'pending');

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$foreignInstallment->id}/due-date",
            ['due_date' => '2027-02-15', 'mode' => 'single', 'confirm' => true],
        )->assertNotFound();
    }

    public function test_socio_gerencia_y_admin_sistema_reciben_403(): void
    {
        $socio = User::factory()->create();
        $this->actingAsRole(RoleName::SOCIO_GERENCIA->value, $socio);

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-04-12', 'mode' => 'single', 'confirm' => true],
        )->assertForbidden();

        $sistema = User::factory()->create();
        $this->actingAsRole(RoleName::ADMIN_SISTEMA->value, $sistema);

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-04-12', 'mode' => 'single', 'confirm' => true],
        )->assertForbidden();
    }

    /**
     * @return array<string, string>
     */
    private function financialSnapshot(AmortizationInstallment $installment): array
    {
        $fresh = $installment->fresh();

        return [
            'installment_value' => (string) $fresh->installment_value,
            'extra_payment' => (string) $fresh->extra_payment,
            'interest_value' => (string) $fresh->interest_value,
            'principal_value' => (string) $fresh->principal_value,
            'interest_paid' => (string) $fresh->interest_paid,
            'principal_paid' => (string) $fresh->principal_paid,
            'quota_debt' => (string) $fresh->quota_debt,
            'remaining_balance' => (string) $fresh->remaining_balance,
            'projected_balance' => (string) $fresh->projected_balance,
            'status' => $fresh->status instanceof AmortizationStatus
                ? $fresh->status->value
                : (string) $fresh->status,
        ];
    }

    private function assertFinancialsUntouched(AmortizationInstallment $installment): void
    {
        $this->assertSame([
            'installment_value' => '1000000.00',
            'extra_payment' => '0.00',
            'interest_value' => '0.00',
            'principal_value' => '1000000.00',
            'interest_paid' => '0.00',
            'principal_paid' => '0.00',
            'quota_debt' => '1000000.00',
            'remaining_balance' => '5000000.00',
            'projected_balance' => '5000000.00',
            'status' => 'overdue',
        ], $this->financialSnapshot($installment));
    }

    private function createPromise(int $number, string $date): ContractPaymentPromise
    {
        return ContractPaymentPromise::query()->create([
            'contract_id' => $this->contract->id,
            'payment_number' => $number,
            'expected_date' => $date,
            'expected_amount' => '1500000.00',
            'description' => 'Pago '.$number,
            'is_paid' => false,
        ]);
    }

    private function createInstallment(
        Contract $contract,
        int $number,
        string $dueDate,
        string $status,
        ?string $paymentDate = null,
    ): AmortizationInstallment {
        return AmortizationInstallment::query()->create([
            'contract_id' => $contract->id,
            'installment_number' => $number,
            'due_date' => $dueDate,
            'payment_date' => $paymentDate,
            'installment_value' => '1000000.00',
            'extra_payment' => '0.00',
            'interest_value' => '0.00',
            'principal_value' => '1000000.00',
            'interest_paid' => '0.00',
            'principal_paid' => '0.00',
            'quota_debt' => $status === 'paid' ? '0.00' : '1000000.00',
            'remaining_balance' => '5000000.00',
            'projected_balance' => '5000000.00',
            'status' => $status,
        ]);
    }
}
