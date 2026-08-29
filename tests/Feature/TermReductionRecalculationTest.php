<?php

namespace Tests\Feature;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use App\Services\Financial\Amortization\AmortizationService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermReductionRecalculationTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = '1.00';

    private const TERM_MONTHS = 12;

    private const SALE_PRICE = '100000000.00';

    private const DOWN_PAYMENT = '20000000.00';

    private const SURPLUS = '20000000.00';

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $project = Project::query()->create([
            'name' => 'Proyecto Reduccion de Plazo',
            'description' => 'Escenario de abono extraordinario con tasa no nula',
            'location' => 'Bogota',
            'status' => 'active',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->contract = Contract::factory()->create([
            'customer_id' => Customer::factory(),
            'lot_id' => Lot::factory()->create(['project_id' => $project->id]),
            'sale_price' => self::SALE_PRICE,
            'down_payment_pactada' => self::DOWN_PAYMENT,
            'term_months' => self::TERM_MONTHS,
            'interest_rate' => self::RATE,
            'status' => 'activo',
            'start_date' => '2025-01-05',
            'initial_payment_date' => '2025-01-05',
            'first_installment_date' => '2025-02-05',
            'regular_payment_start_date' => '2025-02-05',
            'preventa_installments_count' => 0,
        ]);
    }

    public function test_reducir_plazo_regenera_el_futuro_y_respeta_las_cuotas_ya_pagadas(): void
    {
        $calculator = app(AmortizationCalculationService::class);
        app(AmortizationService::class)->generateInitialProjection($this->contract);

        $loanPrincipal = bcsub(self::SALE_PRICE, self::DOWN_PAYMENT, 2);
        $fixedQuota = $calculator->calculateFixedQuota($loanPrincipal, self::RATE, self::TERM_MONTHS);

        $this->markAsPaid(1);
        $this->markAsPaid(2);

        $historic = $this->snapshot([0, 1, 2]);
        $current = $this->installment(3);

        $balanceBeforeSurplus = (string) $current->remaining_balance;
        $expectedBalanceAfterSurplus = bcsub($balanceBeforeSurplus, self::SURPLUS, 2);

        app(ExtraordinaryPaymentService::class)->handle(
            $this->contract,
            $current,
            self::SURPLUS,
            'reducir_plazo',
        );

        // 1. La cuota que recibió el abono queda pagada con el saldo correcto.
        $current->refresh();

        $this->assertSame(AmortizationStatus::PAID->value, $current->status);
        $this->assertSame(self::SURPLUS, (string) $current->extra_payment);
        $this->assertSame($expectedBalanceAfterSurplus, (string) $current->remaining_balance);
        $this->assertSame($expectedBalanceAfterSurplus, (string) $current->projected_balance);
        $this->assertSame($fixedQuota, (string) $current->installment_value);

        // 2. Las cuotas futuras se regeneran sobre el nuevo saldo con la cuota fija original.
        $future = $this->contract->amortizationInstallments()
            ->where('installment_number', '>', 3)
            ->orderBy('installment_number', 'asc')
            ->get();

        $this->assertGreaterThanOrEqual(3, $future->count());

        $balance = $expectedBalanceAfterSurplus;

        foreach ($future->take(3) as $row) {
            $expectedInterest = $calculator->calculateInterest($balance, self::RATE);
            $expectedPrincipal = $calculator->calculatePrincipal($fixedQuota, $expectedInterest);
            $balance = $calculator->calculateRemainingBalance($balance, $expectedPrincipal);

            $label = "cuota futura #{$row->installment_number}";

            // Evita que la comparación pase de forma trivial si la tasa se perdiera por el camino.
            $this->assertGreaterThan(0, (float) $expectedInterest, $label);

            $this->assertSame($fixedQuota, (string) $row->installment_value, $label);
            $this->assertSame($expectedInterest, (string) $row->interest_value, $label);
            $this->assertSame($expectedPrincipal, (string) $row->principal_value, $label);
            $this->assertSame($balance, (string) $row->remaining_balance, $label);
            $this->assertSame($balance, (string) $row->projected_balance, $label);
            $this->assertSame($fixedQuota, (string) $row->quota_debt, $label);
            $this->assertSame(AmortizationStatus::UNPAID->value, $row->status, $label);
        }

        // 3. El histórico anterior a la cuota abonada queda intacto.
        $this->assertSame($historic, $this->snapshot([0, 1, 2]));

        // Reducir plazo mantiene la cuota fija, así que el crédito se salda antes.
        $this->assertLessThan(self::TERM_MONTHS, (int) $this->contract->amortizationInstallments()->max('installment_number'));
    }

    private function installment(int $number): AmortizationInstallment
    {
        return $this->contract->amortizationInstallments()
            ->where('installment_number', $number)
            ->firstOrFail();
    }

    private function markAsPaid(int $number): void
    {
        $installment = $this->installment($number);

        $installment->update([
            'status' => AmortizationStatus::PAID->value,
            'quota_debt' => '0.00',
            'interest_paid' => $installment->interest_value,
            'principal_paid' => $installment->principal_value,
            'payment_date' => $installment->due_date,
        ]);
    }

    private function snapshot(array $numbers): array
    {
        return $this->contract->amortizationInstallments()
            ->whereIn('installment_number', $numbers)
            ->orderBy('installment_number', 'asc')
            ->get()
            ->map(fn (AmortizationInstallment $row) => [
                'installment_number' => (int) $row->installment_number,
                'installment_value' => (string) $row->installment_value,
                'interest_value' => (string) $row->interest_value,
                'principal_value' => (string) $row->principal_value,
                'remaining_balance' => (string) $row->remaining_balance,
                'projected_balance' => (string) $row->projected_balance,
                'status' => (string) $row->status,
            ])
            ->all();
    }
}
