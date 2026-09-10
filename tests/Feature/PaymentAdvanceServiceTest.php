<?php

namespace Tests\Feature;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use App\Services\Financial\Amortization\AmortizationService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentAdvanceServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Números auditados de SM-LOTE-3: extra en #1 → interés de #2 sobre el saldo ya reducido. */
    private const RATE = '1.00';

    private const TERM_MONTHS = 60;

    private const SALE_PRICE = '97116000.00';

    private const DOWN_PAYMENT = '20000000.00';

    private const SURPLUS = '184597.17';

    private const EXPECTED_REMAINING_AFTER_EXTRA = '75987160.00';

    private const EXPECTED_NEXT_INTEREST = '759871.60';

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $project = Project::query()->create([
            'name' => 'Proyecto Adelantar Cuotas',
            'description' => 'Abono extraordinario con recálculo de interés futuro',
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
            'start_date' => '2025-03-15',
            'initial_payment_date' => '2025-03-15',
            'first_installment_date' => '2025-04-15',
            'regular_payment_start_date' => '2025-04-15',
            'preventa_installments_count' => 0,
        ]);
    }

    public function test_adelantar_cuotas_aplica_el_abono_sin_regenerar_el_futuro(): void
    {
        app(AmortizationService::class)->generateInitialProjection($this->contract);

        $current = $this->installment(1);
        $next = $this->installment(2);
        $interestBefore = (string) $next->interest_value;
        $nextId = (int) $next->id;
        $pmt = (string) $current->installment_value;
        $maxBefore = (int) $this->contract->amortizationInstallments()->max('installment_number');
        $futureCountBefore = $this->contract->amortizationInstallments()
            ->where('installment_number', '>', 1)
            ->count();

        app(ExtraordinaryPaymentService::class)->handle(
            $this->contract,
            $current,
            self::SURPLUS,
            'adelantar_cuotas',
        );

        $current->refresh();
        $next->refresh();

        $this->assertSame(AmortizationStatus::PAID, $current->status);
        $this->assertSame(self::SURPLUS, (string) $current->extra_payment);
        $this->assertSame(self::EXPECTED_REMAINING_AFTER_EXTRA, (string) $current->remaining_balance);
        $this->assertSame(self::EXPECTED_NEXT_INTEREST, (string) $next->interest_value);
        $this->assertSame(
            number_format((float) bcsub($pmt, self::EXPECTED_NEXT_INTEREST, 2), 2, '.', ''),
            (string) $next->principal_value,
        );
        $this->assertNotSame($interestBefore, (string) $next->interest_value);
        $this->assertSame($nextId, (int) $next->id);
        $this->assertSame($pmt, (string) $next->installment_value);
        $this->assertSame($futureCountBefore, $this->contract->amortizationInstallments()->where('installment_number', '>', 1)->count());
        $this->assertSame($maxBefore, (int) $this->contract->amortizationInstallments()->max('installment_number'));
        $this->assertSame(self::TERM_MONTHS, $maxBefore);
    }

    private function installment(int $number): AmortizationInstallment
    {
        return $this->contract->amortizationInstallments()
            ->where('installment_number', $number)
            ->firstOrFail();
    }
}
