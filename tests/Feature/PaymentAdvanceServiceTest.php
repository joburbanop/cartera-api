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
            'name' => 'Proyecto Adelantar Cuotas',
            'description' => 'Abono extraordinario sin recálculo de futuro',
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

    public function test_adelantar_cuotas_aplica_el_abono_sin_regenerar_el_futuro(): void
    {
        app(AmortizationService::class)->generateInitialProjection($this->contract);

        $this->markAsPaid(1);
        $this->markAsPaid(2);

        $current = $this->installment(3);
        $balanceBeforeSurplus = (string) $current->remaining_balance;
        $futureBefore = $this->snapshotFuture();
        $maxBefore = (int) $this->contract->amortizationInstallments()->max('installment_number');
        $futureCountBefore = $this->contract->amortizationInstallments()
            ->where('installment_number', '>', 3)
            ->count();

        app(ExtraordinaryPaymentService::class)->handle(
            $this->contract,
            $current,
            self::SURPLUS,
            'adelantar_cuotas',
        );

        $current->refresh();

        $this->assertSame(AmortizationStatus::PAID, $current->status);
        $this->assertSame(self::SURPLUS, (string) $current->extra_payment);
        $this->assertSame(bcsub($balanceBeforeSurplus, self::SURPLUS, 2), (string) $current->remaining_balance);

        $this->assertSame($futureBefore, $this->snapshotFuture());
        $this->assertSame($futureCountBefore, $this->contract->amortizationInstallments()->where('installment_number', '>', 3)->count());
        $this->assertSame($maxBefore, (int) $this->contract->amortizationInstallments()->max('installment_number'));
        $this->assertSame(self::TERM_MONTHS, $maxBefore);
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

    private function snapshotFuture(): array
    {
        return $this->contract->amortizationInstallments()
            ->where('installment_number', '>', 3)
            ->orderBy('installment_number', 'asc')
            ->get()
            ->map(fn (AmortizationInstallment $row) => [
                'id' => (int) $row->id,
                'installment_number' => (int) $row->installment_number,
                'installment_value' => (string) $row->installment_value,
                'interest_value' => (string) $row->interest_value,
                'principal_value' => (string) $row->principal_value,
                'quota_debt' => (string) $row->quota_debt,
                'remaining_balance' => (string) $row->remaining_balance,
                'projected_balance' => (string) $row->projected_balance,
                'status' => $row->status->value,
            ])
            ->all();
    }
}
