<?php

namespace Tests\Feature;

use App\Enums\AllocationTarget;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\ContractPaymentPromise;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use App\Services\Financial\LifeSheet\ContractLifeSheetService;
use App\Services\Financial\Refinancing\AcuerdoPagoService;
use App\Services\Financial\Refinancing\RefinanceContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractLifeSheetTest extends TestCase
{
    use RefreshDatabase;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole(RoleName::ADMINISTRADOR->value);

        $project = Project::query()->create([
            'name' => 'Proyecto HV',
            'description' => 'Fixture hoja de vida',
            'location' => 'Cali',
            'status' => 'active',
        ]);
        $customer = Customer::factory()->create([
            'name' => 'Maria Saavedra',
            'document_number' => '29701754',
            'email' => null,
            'address' => null,
            'phone' => '0000000000',
        ]);
        $lot = Lot::factory()->create([
            'project_id' => $project->id,
            'number' => '49',
            'area_m2' => '0.00',
            'price_m2' => '0.00',
            'list_price' => '122148000.00',
        ]);

        $this->contract = Contract::factory()->create([
            'customer_id' => $customer->id,
            'lot_id' => $lot->id,
            'contract_number' => 'HV-LOTE-49',
            'sale_price' => '122148000.00',
            'down_payment_pactada' => '12214800.00',
            'term_months' => 60,
            'interest_rate' => '1.00',
            'status' => 'activo',
            'seller_name' => 'Importación histórica San Miguel',
            'is_custom_plan' => false,
            'is_special_lot' => false,
        ]);
    }

    public function test_contrato_estandar_usa_pmt_francesa_y_resta_cada_pago(): void
    {
        $calc = app(AmortizationCalculationService::class);
        $pmt = $calc->calculateFixedQuota('109933200.00', '1.00', 60);
        $financed = bcadd('12214800.00', bcmul($pmt, '60', 2), 2);

        $this->createInstallment(0, '12214800.00', '12214800.00', 'paid');
        $this->createInstallment(1, '1099332.00', '0.00', 'pending');

        Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::DOWN_PAYMENT,
            'amount' => '1000000.00',
            'transaction_date' => '2025-08-01',
            'payment_method' => PaymentMethod::TRANSFER,
            'notes' => 'Recibo #0349 | Concepto: CUOTA INICIAL',
        ]);
        Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::DOWN_PAYMENT,
            'amount' => '1000000.00',
            'transaction_date' => '2025-08-04',
            'payment_method' => PaymentMethod::CASH,
            'notes' => 'Recibo #0349 | Concepto: CUOTA INICIAL',
        ]);

        $response = $this->getJson("/api/contracts/{$this->contract->id}/life-sheet");

        $response->assertOk()
            ->assertJsonPath('data.header.financed_value', $financed)
            ->assertJsonPath('data.header.financed_value_basis', 'french_pmt')
            ->assertJsonPath('data.header.monthly_quota', $pmt)
            ->assertJsonPath('data.header.area_m2', null)
            ->assertJsonPath('data.header.email', null)
            ->assertJsonPath('data.header.phone', null)
            ->assertJsonPath('data.header.note', ContractLifeSheetService::NOTE)
            ->assertJsonPath('data.summary.criteria_gap_label', 'Brecha entre criterios')
            ->assertJsonPath('data.rows.0.concept', 'CUOTA INICIAL')
            ->assertJsonPath('data.rows.0.receipt_number', '0349')
            ->assertJsonPath('data.rows.0.bancolombia', '1000000.00')
            ->assertJsonPath('data.rows.0.efectivo', '0.00')
            ->assertJsonPath('data.rows.1.efectivo', '1000000.00')
            ->assertJsonPath('data.rows.1.balance', bcsub(bcsub($financed, '1000000.00', 2), '1000000.00', 2));
    }

    public function test_lote_especial_financiado_igual_al_precio_y_sin_brecha(): void
    {
        $this->contract->update([
            'is_special_lot' => true,
            'sale_price' => '107151000.00',
            'down_payment_pactada' => '107151000.00',
            'term_months' => 0,
            'interest_rate' => '0.00',
        ]);
        $this->createInstallment(0, '107151000.00', '56325000.00', 'partial');

        Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::REGULAR_PAYMENT,
            'amount' => '11325000.00',
            'transaction_date' => '2024-11-26',
            'payment_method' => PaymentMethod::TRANSFER,
            'notes' => 'Recibo #0055 | Concepto: ABONO',
        ]);
        Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::REGULAR_PAYMENT,
            'amount' => '45000000.00',
            'transaction_date' => '2025-02-26',
            'payment_method' => PaymentMethod::TRANSFER,
            'notes' => 'Recibo #0179 | Concepto: ABONO CUOTA',
        ]);

        $response = $this->getJson("/api/contracts/{$this->contract->id}/life-sheet");

        $response->assertOk()
            ->assertJsonPath('data.header.financed_value', '107151000.00')
            ->assertJsonPath('data.header.financed_value_basis', 'sale_price')
            ->assertJsonPath('data.header.monthly_quota', null)
            ->assertJsonPath('data.summary.life_sheet_balance', '50826000.00')
            ->assertJsonPath('data.summary.outstanding_capital', '50826000.00')
            ->assertJsonPath('data.summary.criteria_gap', '0.00')
            ->assertJsonPath('data.summary.amortization_note', ContractLifeSheetService::SPECIAL_AMORTIZATION_NOTE);
    }

    public function test_plan_comercial_usa_inicial_mas_promesas_y_ignora_abonos_de_refinanciacion(): void
    {
        $this->contract->update([
            'is_custom_plan' => true,
            'sale_price' => '105560000.00',
            'down_payment_pactada' => '10556000.00',
            'term_months' => 48,
        ]);

        ContractPaymentPromise::query()->create([
            'contract_id' => $this->contract->id,
            'payment_number' => 1,
            'expected_date' => '2025-11-05',
            'expected_amount' => '1500000.00',
            'description' => 'Cuota comercial',
            'is_paid' => false,
        ]);
        ContractPaymentPromise::query()->create([
            'contract_id' => $this->contract->id,
            'payment_number' => 2,
            'expected_date' => '2025-12-05',
            'expected_amount' => '118587360.00',
            'description' => 'Resto comercial',
            'is_paid' => false,
        ]);
        ContractPaymentPromise::query()->create([
            'contract_id' => $this->contract->id,
            'payment_number' => 3,
            'expected_date' => '2026-01-05',
            'expected_amount' => '250000.00',
            'description' => AcuerdoPagoService::DESCRIPTION,
            'is_paid' => false,
        ]);

        $response = $this->getJson("/api/contracts/{$this->contract->id}/life-sheet");

        $response->assertOk()
            ->assertJsonPath('data.header.financed_value', '130643360.00')
            ->assertJsonPath('data.header.financed_value_basis', 'commercial_promises')
            ->assertJsonPath('data.header.monthly_quota', null);
    }

    public function test_despues_de_refinanciar_saldo_el_valor_financiado_vuelve_a_la_pmt_francesa(): void
    {
        $this->contract->update([
            'is_custom_plan' => true,
            'sale_price' => '9000000.00',
            'down_payment_pactada' => '3000000.00',
            'term_months' => 12,
            'interest_rate' => '1.00',
        ]);
        ContractPaymentPromise::query()->create([
            'contract_id' => $this->contract->id,
            'payment_number' => 1,
            'expected_date' => '2027-01-05',
            'expected_amount' => '999999999.00',
            'description' => 'Promesa vieja del PDF',
            'is_paid' => false,
        ]);

        activity(RefinanceContractService::LOG_NAME)
            ->performedOn($this->contract)
            ->log('Refinanció el contrato mediante refinanciar_saldo');

        $pmt = app(AmortizationCalculationService::class)
            ->calculateFixedQuota('6000000.00', '1.00', 12);
        $financed = bcadd('3000000.00', bcmul($pmt, '12', 2), 2);

        $response = $this->getJson("/api/contracts/{$this->contract->id}/life-sheet");

        $response->assertOk()
            ->assertJsonPath('data.header.financed_value_basis', 'french_pmt')
            ->assertJsonPath('data.header.financed_value', $financed);
    }

    public function test_pago_nuevo_sin_notes_usa_concepto_generico(): void
    {
        $this->createInstallment(0, '12214800.00', '0.00', 'pending');

        Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::REGULAR_PAYMENT,
            'amount' => '500000.00',
            'transaction_date' => '2026-09-08',
            'payment_method' => PaymentMethod::BANK,
            'notes' => null,
        ]);

        $this->getJson("/api/contracts/{$this->contract->id}/life-sheet")
            ->assertOk()
            ->assertJsonPath('data.rows.0.concept', 'PAGO')
            ->assertJsonPath('data.rows.0.receipt_number', null)
            ->assertJsonPath('data.rows.0.occidente', '500000.00');
    }

    public function test_pago_nuevo_con_allocations_usa_cuota_y_rango(): void
    {
        $seven = $this->createInstallment(7, '2000000.00', '0.00', 'paid');
        $eight = $this->createInstallment(8, '2000000.00', '0.00', 'paid');
        $nine = $this->createInstallment(9, '2000000.00', '0.00', 'paid');

        $tx = Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::REGULAR_PAYMENT,
            'amount' => '4018003.00',
            'transaction_date' => '2026-09-10',
            'payment_method' => PaymentMethod::CASH,
            'notes' => 'Recibo #032',
            'receipt_number' => '032',
        ]);

        foreach ([$seven, $eight, $nine] as $installment) {
            TransactionAllocation::query()->create([
                'transaction_id' => $tx->id,
                'target' => AllocationTarget::INSTALLMENT,
                'amortization_installment_id' => $installment->id,
                'amount' => '1339334.33',
                'principal' => '1000000.00',
                'interest' => '339334.33',
            ]);
        }

        $this->getJson("/api/contracts/{$this->contract->id}/life-sheet")
            ->assertOk()
            ->assertJsonPath('data.rows.0.concept', 'CUOTA 7-8-9')
            ->assertJsonPath('data.rows.0.receipt_number', '032');
    }

    public function test_consolidado_desglosa_lo_pagado_en_interes_y_capital(): void
    {
        // Inicial: 100% capital. Cuota 1: interés primero, luego capital.
        $this->createInstallment(0, '12214800.00', '12214800.00', 'paid');
        $this->createInstallmentWithInterest(1, '1099332.00', '150000.00', '949332.00', 'paid');

        Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::DOWN_PAYMENT,
            'amount' => '12214800.00',
            'transaction_date' => '2025-08-01',
            'payment_method' => PaymentMethod::TRANSFER,
            'notes' => 'Recibo #0349 | Concepto: CUOTA INICIAL',
        ]);
        Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::REGULAR_PAYMENT,
            'amount' => '1099332.00',
            'transaction_date' => '2025-09-10',
            'payment_method' => PaymentMethod::CASH,
            'notes' => 'Recibo #0350 | Concepto: CUOTA 1',
        ]);

        $collected = bcadd('12214800.00', '1099332.00', 2);
        $capital = bcadd('12214800.00', '949332.00', 2);

        $this->getJson("/api/contracts/{$this->contract->id}/life-sheet")
            ->assertOk()
            ->assertJsonPath('data.summary.collected', $collected)
            ->assertJsonPath('data.summary.interest_paid', '150000.00')
            ->assertJsonPath('data.summary.principal_paid', $capital)
            // Interés + capital cuadra con el total: no queda dinero sin imputar.
            ->assertJsonPath('data.summary.unimputed', '0.00')
            // El acumulado por fila crece pago a pago hasta el total.
            ->assertJsonPath('data.rows.0.total_paid', '12214800.00')
            ->assertJsonPath('data.rows.1.total_paid', $collected);
    }

    public function test_consolidado_reporta_lo_recaudado_que_no_llego_a_las_cuotas(): void
    {
        $this->createInstallment(0, '12214800.00', '0.00', 'pending');

        // Interés diferido: entra a caja pero no toca las cuotas.
        Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::DEFERRED_INTEREST,
            'amount' => '300000.00',
            'transaction_date' => '2025-08-01',
            'payment_method' => PaymentMethod::TRANSFER,
            'notes' => 'Recibo #0400 | Concepto: INTERES DIFERIDO',
        ]);

        $this->getJson("/api/contracts/{$this->contract->id}/life-sheet")
            ->assertOk()
            ->assertJsonPath('data.summary.collected', '300000.00')
            ->assertJsonPath('data.summary.interest_paid', '0.00')
            ->assertJsonPath('data.summary.principal_paid', '0.00')
            ->assertJsonPath('data.summary.unimputed', '300000.00');
    }

    public function test_socio_gerencia_puede_ver_la_hoja_de_vida(): void
    {
        $this->actingAsRole(RoleName::SOCIO_GERENCIA->value);

        $this->getJson("/api/contracts/{$this->contract->id}/life-sheet")
            ->assertOk();
    }

    public function test_par_revertido_y_reversa_siguen_visibles_pero_no_mueven_total_pagado_ni_saldo(): void
    {
        $this->createInstallment(1, '2000000.00', '800000.00', 'partial');

        Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::REGULAR_PAYMENT,
            'amount' => '500000.00',
            'transaction_date' => '2026-02-01',
            'payment_method' => PaymentMethod::CASH,
            'receipt_number' => '100',
            'notes' => 'Recibo #100',
        ]);

        $reversed = Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::REGULAR_PAYMENT,
            'amount' => '200000.00',
            'transaction_date' => '2026-02-02',
            'payment_method' => PaymentMethod::CASH,
            'receipt_number' => '8484',
            'notes' => 'Recibo #8484',
            'reversed_at' => now(),
        ]);

        Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::PAYMENT_REVERSAL,
            'amount' => '200000.00',
            'transaction_date' => '2026-02-03',
            'payment_method' => PaymentMethod::CASH,
            'notes' => 'Reversa del recibo 8484',
            'reversal_transaction_id' => null,
        ]);

        Transaction::query()->create([
            'contract_id' => $this->contract->id,
            'transaction_type' => TransactionType::REGULAR_PAYMENT,
            'amount' => '300000.00',
            'transaction_date' => '2026-02-04',
            'payment_method' => PaymentMethod::TRANSFER,
            'receipt_number' => '332',
            'notes' => 'Recibo #332',
        ]);

        $collected = bcadd('500000.00', '300000.00', 2);
        $firstBalance = null;

        $response = $this->getJson("/api/contracts/{$this->contract->id}/life-sheet");
        $firstBalance = $response->json('data.rows.0.balance');

        $response->assertOk()
            ->assertJsonCount(4, 'data.rows')
            ->assertJsonPath('data.summary.collected', $collected)
            ->assertJsonPath('data.rows.0.receipt_number', '100')
            ->assertJsonPath('data.rows.0.total_paid', '500000.00')
            ->assertJsonPath('data.rows.0.affects_running_total', true)
            ->assertJsonPath('data.rows.1.receipt_number', '8484')
            ->assertJsonPath('data.rows.1.amount', '200000.00')
            ->assertJsonPath('data.rows.1.efectivo', '200000.00')
            ->assertJsonPath('data.rows.1.total_paid', '500000.00')
            ->assertJsonPath('data.rows.1.balance', $firstBalance)
            ->assertJsonPath('data.rows.1.affects_running_total', false)
            ->assertJsonPath('data.rows.1.concept', 'PAGO (revertido, no afecta el saldo)')
            ->assertJsonPath('data.rows.2.concept', 'REVERSA DE PAGO (no afecta el saldo)')
            ->assertJsonPath('data.rows.2.amount', '200000.00')
            ->assertJsonPath('data.rows.2.total_paid', '500000.00')
            ->assertJsonPath('data.rows.2.balance', $firstBalance)
            ->assertJsonPath('data.rows.2.affects_running_total', false)
            ->assertJsonPath('data.rows.3.receipt_number', '332')
            ->assertJsonPath('data.rows.3.total_paid', $collected)
            ->assertJsonPath('data.rows.3.affects_running_total', true);

        $this->assertNotSame($reversed->id, $response->json('data.rows.3.transaction_id'));
    }

    private function createInstallment(int $number, string $principal, string $paid, string $status): AmortizationInstallment
    {
        return AmortizationInstallment::query()->create([
            'contract_id' => $this->contract->id,
            'installment_number' => $number,
            'due_date' => '2025-09-10',
            'installment_value' => $principal,
            'extra_payment' => '0.00',
            'interest_value' => '0.00',
            'principal_value' => $principal,
            'interest_paid' => '0.00',
            'principal_paid' => $paid,
            'quota_debt' => $status === 'paid' ? '0.00' : bcsub($principal, $paid, 2),
            'remaining_balance' => '0.00',
            'projected_balance' => '0.00',
            'status' => $status,
        ]);
    }

    private function createInstallmentWithInterest(
        int $number,
        string $installmentValue,
        string $interestPaid,
        string $principalPaid,
        string $status
    ): void {
        AmortizationInstallment::query()->create([
            'contract_id' => $this->contract->id,
            'installment_number' => $number,
            'due_date' => '2025-09-10',
            'installment_value' => $installmentValue,
            'extra_payment' => '0.00',
            'interest_value' => $interestPaid,
            'principal_value' => $principalPaid,
            'interest_paid' => $interestPaid,
            'principal_paid' => $principalPaid,
            'quota_debt' => $status === 'paid' ? '0.00' : $installmentValue,
            'remaining_balance' => '0.00',
            'projected_balance' => '0.00',
            'status' => $status,
        ]);
    }
}
