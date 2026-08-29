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

class PaymentReductionRecalculationTest extends TestCase
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
            'name' => 'Proyecto Reduccion de Cuota',
            'description' => 'Escenario de reducir_cuota con tasa no nula',
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

    public function test_reducir_cuota_recalcula_la_pmt_y_conserva_el_numero_de_cuotas_futuras(): void
    {
        $calculator = app(AmortizationCalculationService::class);
        app(AmortizationService::class)->generateInitialProjection($this->contract);

        $this->markAsPaid(1);
        $this->markAsPaid(2);

        $historic = $this->snapshot([0, 1, 2]);
        $current = $this->installment(3);
        $futureCountBefore = $this->contract->amortizationInstallments()
            ->where('installment_number', '>', 3)
            ->count();
        $futureIdsBefore = $this->contract->amortizationInstallments()
            ->where('installment_number', '>', 3)
            ->orderBy('installment_number', 'asc')
            ->pluck('id')
            ->all();

        $balanceBeforeSurplus = (string) $current->remaining_balance;

        app(ExtraordinaryPaymentService::class)->handle(
            $this->contract,
            $current,
            self::SURPLUS,
            'reducir_cuota',
        );

        $current->refresh();
        $expectedBalanceAfterSurplus = (string) $current->remaining_balance;

        $this->assertSame(AmortizationStatus::PAID, $current->status);
        $this->assertSame(self::SURPLUS, (string) $current->extra_payment);
        $this->assertSame(bcsub($balanceBeforeSurplus, self::SURPLUS, 2), $expectedBalanceAfterSurplus);

        $future = $this->contract->amortizationInstallments()
            ->where('installment_number', '>', 3)
            ->orderBy('installment_number', 'asc')
            ->get();

        $this->assertSame($futureCountBefore, $future->count());
        $this->assertSame($futureIdsBefore, $future->pluck('id')->all());
        $this->assertSame(self::TERM_MONTHS, (int) $this->contract->amortizationInstallments()->max('installment_number'));

        $newQuota = $calculator->calculateFixedQuota(
            $expectedBalanceAfterSurplus,
            self::RATE,
            $future->count(),
        );

        $this->assertNotSame(
            $calculator->calculateFixedQuota(
                bcsub(self::SALE_PRICE, self::DOWN_PAYMENT, 2),
                self::RATE,
                self::TERM_MONTHS,
            ),
            $newQuota,
        );

        $balance = $expectedBalanceAfterSurplus;

        foreach ($future->take(3) as $row) {
            $expectedInterest = $calculator->calculateInterest($balance, self::RATE);
            $expectedPrincipal = $calculator->calculatePrincipal($newQuota, $expectedInterest);
            $balance = $calculator->calculateRemainingBalance($balance, $expectedPrincipal);

            $label = "cuota futura #{$row->installment_number}";

            $this->assertGreaterThan(0, (float) $expectedInterest, $label);
            $this->assertSame($newQuota, (string) $row->installment_value, $label);
            $this->assertSame($expectedInterest, (string) $row->interest_value, $label);
            $this->assertSame($expectedPrincipal, (string) $row->principal_value, $label);
            $this->assertSame($balance, (string) $row->remaining_balance, $label);
            $this->assertSame($balance, (string) $row->projected_balance, $label);
            $this->assertSame($newQuota, (string) $row->quota_debt, $label);
            $this->assertSame(AmortizationStatus::PENDING, $row->status, $label);
        }

        $this->assertSame($historic, $this->snapshot([0, 1, 2]));
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
                'status' => $row->status->value,
            ])
            ->all();
    }
}
