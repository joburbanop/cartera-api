<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogIndexTest extends TestCase
{
    use RefreshDatabase;

    private Contract $contract;

    private Customer $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->actingAsRole(RoleName::ADMINISTRADOR->value);

        $project = Project::query()->create([
            'name' => 'Proyecto Bitacora',
            'description' => 'Fixture bitacora',
            'location' => 'Bogota',
            'status' => 'active',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->customer = Customer::factory()->create([
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $lot = Lot::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->contract = Contract::factory()->create([
            'customer_id' => $this->customer->id,
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
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    public function test_socio_gerencia_puede_consultar_la_bitacora_de_un_subject(): void
    {
        $this->customer->update(['phone' => '3000000000']);

        $this->actingAsRole(RoleName::SOCIO_GERENCIA->value, User::factory()->create());

        $this->getJson("/api/activity?subject_type=customer&subject_id={$this->customer->id}")
            ->assertOk()
            ->assertJsonPath('data.0.description', 'Actualizó cliente')
            ->assertJsonPath('data.0.causer_name', $this->admin->name)
            ->assertJsonPath('data.0.changes.after.phone', '3000000000');
    }

    public function test_administrador_y_admin_sistema_reciben_403(): void
    {
        $this->getJson("/api/activity?subject_type=contract&subject_id={$this->contract->id}")
            ->assertForbidden();

        $this->actingAsRole(RoleName::ADMIN_SISTEMA->value, User::factory()->create());

        $this->getJson("/api/activity?subject_type=contract&subject_id={$this->contract->id}")
            ->assertForbidden();
    }

    public function test_subject_type_invalido_da_422(): void
    {
        $this->actingAsRole(RoleName::SOCIO_GERENCIA->value, User::factory()->create());

        $this->getJson('/api/activity?subject_type=foo&subject_id=1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_type']);
    }

    public function test_subject_id_inexistente_da_404(): void
    {
        $this->actingAsRole(RoleName::SOCIO_GERENCIA->value, User::factory()->create());

        $this->getJson('/api/activity?subject_type=contract&subject_id=999999')
            ->assertNotFound();
    }

    public function test_registra_bitacora_manual_de_cambio_de_fecha_y_pago(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-02-10 10:00:00'));

        $installment = AmortizationInstallment::query()->create([
            'contract_id' => $this->contract->id,
            'installment_number' => 1,
            'due_date' => '2027-03-05',
            'installment_value' => '1000000.00',
            'extra_payment' => '0.00',
            'interest_value' => '0.00',
            'principal_value' => '1000000.00',
            'interest_paid' => '0.00',
            'principal_paid' => '0.00',
            'quota_debt' => '1000000.00',
            'remaining_balance' => '1000000.00',
            'projected_balance' => '1000000.00',
            'status' => 'pending',
        ]);

        $this->patchJson(
            "/api/contracts/{$this->contract->id}/installments/{$installment->id}/due-date",
            ['due_date' => '2027-03-10'],
        )->assertOk();

        $this->postJson('/api/collections/cascade', [
            'contract_id' => $this->contract->id,
            'amount' => '1000000.00',
            'payment_method' => 'cash',
            'payment_date' => '2027-02-10',
            'selected_installments' => [$installment->id],
        ])->assertCreated();

        $this->actingAsRole(RoleName::SOCIO_GERENCIA->value, User::factory()->create());

        $response = $this->getJson("/api/activity?subject_type=contract&subject_id={$this->contract->id}")
            ->assertOk();

        $response->assertJsonPath('data.0.description', 'Registró un pago de $1,000.00 mediante cash sobre el contrato');
        $response->assertJsonPath('data.0.properties.transaction_type', 'regular_payment');
        $response->assertJsonPath('data.1.description', 'Cambió la fecha de vencimiento de la cuota 1 de 05/03/2027 a 10/03/2027');
        $response->assertJsonPath('data.1.changes.before.due_date', '2027-03-05');
        $response->assertJsonPath('data.1.changes.after.due_date', '2027-03-10');

        Carbon::setTestNow();
    }
}
