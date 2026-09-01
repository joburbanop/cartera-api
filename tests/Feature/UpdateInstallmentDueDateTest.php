<?php

namespace Tests\Feature;

use App\Enums\AmortizationStatus;
use App\Enums\RoleName;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->installment1 = $this->createInstallment($this->contract, 1, '2027-03-05', 'pending');
        $this->installment2 = $this->createInstallment($this->contract, 2, '2027-04-05', 'overdue');
        $this->installment3 = $this->createInstallment($this->contract, 3, '2027-05-05', 'pending');
        $this->paidInstallment = $this->createInstallment($this->contract, 4, '2027-06-05', 'paid');
    }

    public function test_administrador_puede_editar_vencida_y_pendiente_dentro_de_rango(): void
    {
        $responseOverdue = $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-04-10'],
        );

        $responseOverdue->assertOk()
            ->assertJsonPath('data.id', $this->installment2->id)
            ->assertJsonPath('data.due_date', fn ($value) => is_string($value) && str_starts_with($value, '2027-04-10'));

        $responsePending = $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment3->id}/due-date",
            ['due_date' => '2027-05-15'],
        );

        $responsePending->assertOk();

        $this->assertSame('2027-04-10', $this->installment2->fresh()->due_date->toDateString());
        $this->assertSame('2027-05-15', $this->installment3->fresh()->due_date->toDateString());
    }

    public function test_permite_una_fecha_fuera_de_orden_respecto_a_las_cuotas_vecinas(): void
    {
        $response = $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-06-10'],
        );

        $response->assertOk()
            ->assertJsonPath('data.id', $this->installment2->id)
            ->assertJsonPath('data.due_date', fn ($value) => is_string($value) && str_starts_with($value, '2027-06-10'));

        $this->assertSame('2027-06-10', $this->installment2->fresh()->due_date->toDateString());
    }

    public function test_rechaza_si_la_cuota_ya_esta_pagada(): void
    {
        $response = $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->paidInstallment->id}/due-date",
            ['due_date' => '2027-06-10'],
        );

        $response->assertUnprocessable()
            ->assertJsonPath('errors.installment.0', 'No se puede modificar la fecha de una cuota ya pagada.');
    }

    public function test_rechaza_si_installment_number_es_0(): void
    {
        $response = $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment0->id}/due-date",
            ['due_date' => '2027-01-10'],
        );

        $response->assertUnprocessable()
            ->assertJsonPath('errors.installment.0', 'La cuota inicial no se puede modificar desde este flujo.');
    }

    public function test_responde_404_si_la_cuota_no_pertenece_al_contrato(): void
    {
        $foreignInstallment = $this->createInstallment($this->otherContract, 1, '2027-02-10', 'pending');

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$foreignInstallment->id}/due-date",
            ['due_date' => '2027-02-15'],
        )->assertNotFound();
    }

    public function test_socio_gerencia_y_admin_sistema_reciben_403(): void
    {
        $socio = User::factory()->create();
        $this->actingAsRole(RoleName::SOCIO_GERENCIA->value, $socio);

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-04-12'],
        )->assertForbidden();

        $sistema = User::factory()->create();
        $this->actingAsRole(RoleName::ADMIN_SISTEMA->value, $sistema);

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$this->installment2->id}/due-date",
            ['due_date' => '2027-04-12'],
        )->assertForbidden();
    }

    private function createInstallment(
        Contract $contract,
        int $number,
        string $dueDate,
        string $status,
    ): AmortizationInstallment {
        return AmortizationInstallment::query()->create([
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
            'remaining_balance' => '5000000.00',
            'projected_balance' => '5000000.00',
            'status' => $status,
        ]);
    }
}
