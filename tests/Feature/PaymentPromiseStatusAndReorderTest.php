<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\ContractPaymentPromise;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Financial\Refinancing\AcuerdoPagoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentPromiseStatusAndReorderTest extends TestCase
{
    use RefreshDatabase;

    private Contract $contract;

    private ContractPaymentPromise $promiseA;

    private ContractPaymentPromise $promiseB;

    private ContractPaymentPromise $promiseC;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-04-10 12:00:00'));

        $admin = $this->actingAsRole(RoleName::ADMINISTRADOR->value);
        $project = Project::query()->create([
            'name' => 'Proyecto Cronograma',
            'description' => 'Fixture promesas',
            'location' => 'Bogota',
            'status' => 'active',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->contract = Contract::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'lot_id' => Lot::factory()->create(['project_id' => $project->id])->id,
            'is_custom_plan' => true,
            'status' => 'activo',
        ]);

        $this->promiseA = $this->createPromise(1, '2027-03-05', '1000000.00');
        $this->promiseB = $this->createPromise(2, '2027-04-05', '500000.00');
        $this->promiseC = $this->createPromise(3, '2027-05-05', '800000.00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pago_exacto_de_una_promesa_la_marca_pagada_y_deja_la_siguiente_pendiente_o_vencida(): void
    {
        $this->createPayment('1000000.00');

        $response = $this->getJson("/api/contracts/{$this->contract->id}/payment-promises");

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'pagada')
            ->assertJsonPath('data.0.remaining_amount', '0.00')
            ->assertJsonPath('data.1.status', 'vencida')
            ->assertJsonPath('data.1.remaining_amount', '500000.00')
            ->assertJsonPath('data.2.status', 'pendiente')
            ->assertJsonPath('data.2.remaining_amount', '800000.00');
    }

    public function test_pago_que_cubre_dos_promesas_deja_la_tercera_intacta(): void
    {
        $this->createPayment('1500000.00');

        $this->getJson("/api/contracts/{$this->contract->id}/payment-promises")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'pagada')
            ->assertJsonPath('data.1.status', 'pagada')
            ->assertJsonPath('data.2.status', 'pendiente');
    }

    public function test_pago_parcial_de_una_sola_promesa_marca_parcial_y_corta_el_acumulado(): void
    {
        $this->createPayment('400000.00');

        $this->getJson("/api/contracts/{$this->contract->id}/payment-promises")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'parcial')
            ->assertJsonPath('data.0.remaining_amount', '600000.00')
            ->assertJsonPath('data.1.status', 'vencida')
            ->assertJsonPath('data.2.status', 'pendiente');
    }

    public function test_un_abono_de_cuota_inicial_no_se_reparte_sobre_el_cronograma(): void
    {
        Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::DOWN_PAYMENT->value,
            'amount' => '1000000.00',
            'transaction_date' => '2027-03-01',
            'payment_method' => PaymentMethod::TRANSFER->value,
        ]);

        $this->getJson("/api/contracts/{$this->contract->id}/payment-promises")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'vencida')
            ->assertJsonPath('data.0.remaining_amount', '1000000.00')
            ->assertJsonPath('data.1.status', 'vencida')
            ->assertJsonPath('data.2.status', 'pendiente');
    }

    public function test_reordenar_actualiza_fechas_y_rechaza_mover_una_pagada(): void
    {
        $this->patchJson("/api/contracts/{$this->contract->id}/payment-promises/reorder", [
            'promises' => [
                ['id' => $this->promiseC->id, 'expected_date' => '2027-03-05'],
                ['id' => $this->promiseA->id, 'expected_date' => '2027-04-05'],
                ['id' => $this->promiseB->id, 'expected_date' => '2027-05-05'],
            ],
        ])->assertOk();

        $this->assertSame('2027-03-05', $this->promiseC->fresh()->expected_date->toDateString());
        $this->assertSame('2027-04-05', $this->promiseA->fresh()->expected_date->toDateString());
        $this->assertSame('2027-05-05', $this->promiseB->fresh()->expected_date->toDateString());
        $this->assertSame(1, (int) $this->promiseC->fresh()->payment_number);
        $this->assertSame(2, (int) $this->promiseA->fresh()->payment_number);

        $this->createPayment('800000.00');

        $this->patchJson("/api/contracts/{$this->contract->id}/payment-promises/reorder", [
            'promises' => [
                ['id' => $this->promiseC->id, 'expected_date' => '2027-06-05'],
                ['id' => $this->promiseA->id, 'expected_date' => '2027-04-05'],
                ['id' => $this->promiseB->id, 'expected_date' => '2027-05-05'],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('errors.promises.0', 'No se puede mover una promesa ya pagada.');
    }

    public function test_socio_gerencia_recibe_403_al_reordenar(): void
    {
        $this->actingAsRole(RoleName::SOCIO_GERENCIA->value, User::factory()->create());

        $this->patchJson("/api/contracts/{$this->contract->id}/payment-promises/reorder", [
            'promises' => [
                ['id' => $this->promiseA->id, 'expected_date' => '2027-03-05'],
                ['id' => $this->promiseB->id, 'expected_date' => '2027-04-05'],
                ['id' => $this->promiseC->id, 'expected_date' => '2027-05-05'],
            ],
        ])->assertForbidden();
    }

    public function test_guardar_plan_comercial_no_borra_abonos_de_refinanciacion(): void
    {
        $refiA = ContractPaymentPromise::query()->create([
            'contract_id' => $this->contract->id,
            'payment_number' => 4,
            'expected_date' => '2027-06-05',
            'expected_amount' => '250000.00',
            'description' => AcuerdoPagoService::DESCRIPTION,
            'is_paid' => false,
        ]);
        $refiB = ContractPaymentPromise::query()->create([
            'contract_id' => $this->contract->id,
            'payment_number' => 5,
            'expected_date' => '2027-07-05',
            'expected_amount' => '250000.00',
            'description' => AcuerdoPagoService::DESCRIPTION,
            'is_paid' => false,
        ]);

        $this->postJson("/api/contracts/{$this->contract->id}/payment-promises", [
            'promises' => [
                [
                    'payment_number' => 1,
                    'expected_date' => '2027-03-10',
                    'expected_amount' => '1200000.00',
                    'description' => 'Cuota comercial 1',
                ],
                [
                    'payment_number' => 2,
                    'expected_date' => '2027-04-10',
                    'expected_amount' => '900000.00',
                    'description' => 'Cuota comercial 2',
                ],
            ],
        ])->assertCreated();

        $commercial = ContractPaymentPromise::query()
            ->where('contract_id', $this->contract->id)
            ->where('description', '!=', AcuerdoPagoService::DESCRIPTION)
            ->orderBy('payment_number')
            ->get();

        $refinance = ContractPaymentPromise::query()
            ->where('contract_id', $this->contract->id)
            ->where('description', AcuerdoPagoService::DESCRIPTION)
            ->orderBy('payment_number')
            ->get();

        $this->assertCount(2, $commercial);
        $this->assertSame(['1200000.00', '900000.00'], $commercial->pluck('expected_amount')->map(
            fn ($v) => number_format((float) $v, 2, '.', '')
        )->all());
        $this->assertSame(['Cuota comercial 1', 'Cuota comercial 2'], $commercial->pluck('description')->all());

        $this->assertCount(2, $refinance);
        $this->assertTrue($refinance->contains(fn ($row) => (int) $row->id === $refiA->id));
        $this->assertTrue($refinance->contains(fn ($row) => (int) $row->id === $refiB->id));
        $this->assertSame('250000.00', number_format((float) $refiA->fresh()->expected_amount, 2, '.', ''));
        $this->assertSame('2027-06-05', $refiA->fresh()->expected_date->toDateString());
        $this->assertSame('2027-07-05', $refiB->fresh()->expected_date->toDateString());

        $this->assertDatabaseMissing('contract_payment_promises', ['id' => $this->promiseA->id]);
        $this->assertDatabaseMissing('contract_payment_promises', ['id' => $this->promiseB->id]);
        $this->assertDatabaseMissing('contract_payment_promises', ['id' => $this->promiseC->id]);
    }

    public function test_guardar_plan_comercial_corre_los_abonos_de_refinanciacion_para_no_repetir_numero(): void
    {
        foreach ([['2027-06-05', 4], ['2027-07-05', 5], ['2027-08-05', 6]] as [$fecha, $numero]) {
            ContractPaymentPromise::query()->create([
                'contract_id' => $this->contract->id,
                'payment_number' => $numero,
                'expected_date' => $fecha,
                'expected_amount' => '250000.00',
                'description' => AcuerdoPagoService::DESCRIPTION,
                'is_paid' => false,
            ]);
        }

        $this->postJson("/api/contracts/{$this->contract->id}/payment-promises", [
            'promises' => [
                ['payment_number' => 1, 'expected_date' => '2027-03-10', 'expected_amount' => '1200000.00', 'description' => 'Cuota comercial 1'],
                ['payment_number' => 2, 'expected_date' => '2027-04-10', 'expected_amount' => '900000.00', 'description' => 'Cuota comercial 2'],
            ],
        ])->assertCreated();

        $todas = ContractPaymentPromise::query()
            ->where('contract_id', $this->contract->id)
            ->orderBy('payment_number')
            ->get();

        $numeros = $todas->pluck('payment_number')->map(fn ($n) => (int) $n)->all();
        $this->assertSame([1, 2, 3, 4, 5], $numeros, 'no debe haber payment_number repetido');

        $this->assertSame(
            ['Cuota comercial 1', 'Cuota comercial 2'],
            $todas->take(2)->pluck('description')->all(),
            'el plan comercial conserva la numeración 1..N',
        );
        $this->assertSame(
            ['2027-06-05', '2027-07-05', '2027-08-05'],
            $todas->slice(2)->map(fn ($p) => $p->expected_date->toDateString())->values()->all(),
            'los abonos de refinanciación quedan detrás y en su orden de fechas',
        );
    }

    public function test_guardar_plan_comercial_ignora_filas_con_descripcion_de_refinanciacion(): void
    {
        ContractPaymentPromise::query()->create([
            'contract_id' => $this->contract->id,
            'payment_number' => 10,
            'expected_date' => '2027-08-05',
            'expected_amount' => '300000.00',
            'description' => AcuerdoPagoService::DESCRIPTION,
            'is_paid' => false,
        ]);

        $this->postJson("/api/contracts/{$this->contract->id}/payment-promises", [
            'promises' => [
                [
                    'payment_number' => 1,
                    'expected_date' => '2027-03-10',
                    'expected_amount' => '1000000.00',
                    'description' => 'Cuota comercial',
                ],
                [
                    'payment_number' => 2,
                    'expected_date' => '2027-04-10',
                    'expected_amount' => '999999.00',
                    'description' => AcuerdoPagoService::DESCRIPTION,
                ],
            ],
        ])->assertCreated();

        $this->assertSame(1, ContractPaymentPromise::query()
            ->where('contract_id', $this->contract->id)
            ->where('description', '!=', AcuerdoPagoService::DESCRIPTION)
            ->count());

        $this->assertSame(1, ContractPaymentPromise::query()
            ->where('contract_id', $this->contract->id)
            ->where('description', AcuerdoPagoService::DESCRIPTION)
            ->count());

        $this->assertSame(
            '300000.00',
            number_format((float) ContractPaymentPromise::query()
                ->where('contract_id', $this->contract->id)
                ->where('description', AcuerdoPagoService::DESCRIPTION)
                ->value('expected_amount'), 2, '.', ''),
        );
    }

    private function createPromise(int $number, string $date, string $amount): ContractPaymentPromise
    {
        return ContractPaymentPromise::query()->create([
            'contract_id' => $this->contract->id,
            'payment_number' => $number,
            'expected_date' => $date,
            'expected_amount' => $amount,
            'description' => "Cuota {$number}",
            'is_paid' => false,
        ]);
    }

    private function createPayment(string $amount): Transaction
    {
        return Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::REGULAR_PAYMENT->value,
            'amount' => $amount,
            'transaction_date' => '2027-04-01',
            'payment_method' => PaymentMethod::TRANSFER->value,
        ]);
    }
}
