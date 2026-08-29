<?php

namespace Tests\Feature;

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use App\Services\Financial\Amortization\AmortizationService;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DownPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SALE_PRICE = '100000000.00';

    private const DOWN_PAYMENT = '20000000.00';

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $project = Project::query()->create([
            'name' => 'Proyecto Cuota Inicial',
            'description' => 'Validaciones de DownPaymentService',
            'location' => 'Bogota',
            'status' => 'active',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->contract = Contract::factory()->create([
            'customer_id' => Customer::factory(),
            'lot_id' => Lot::factory()->create([
                'project_id' => $project->id,
                'status' => LotStatus::DISPONIBLE->value,
            ]),
            'sale_price' => self::SALE_PRICE,
            'down_payment_pactada' => self::DOWN_PAYMENT,
            'term_months' => 12,
            'interest_rate' => '1.00',
            'status' => ContractStatus::PREVENTA_INACTIVA->value,
            'start_date' => '2025-01-05',
            'initial_payment_date' => '2025-01-05',
            'first_installment_date' => '2025-02-05',
            'regular_payment_start_date' => '2025-02-05',
            'preventa_installments_count' => 0,
        ]);

        app(AmortizationService::class)->generateInitialProjection($this->contract);
    }

    public function test_rechaza_un_abono_que_supera_el_saldo_pendiente_de_la_inicial(): void
    {
        try {
            $this->pay('25000000.00');
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(
                'El monto supera el saldo pendiente de la cuota inicial.',
                $e->errors()['amount'][0],
            );
        }

        $this->assertSame(ContractStatus::PREVENTA_INACTIVA, $this->contract->fresh()->status);
        $this->assertSame(LotStatus::DISPONIBLE, $this->contract->lot->fresh()->status);
        $this->assertSame(self::DOWN_PAYMENT, (string) $this->initialInstallment()->quota_debt);
    }

    public function test_rechaza_un_abono_si_la_cuota_inicial_ya_esta_saldada(): void
    {
        $this->pay(self::DOWN_PAYMENT);

        try {
            $this->pay('100.00');
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(
                'La cuota inicial ya se encuentra completamente pagada.',
                $e->errors()['amount'][0],
            );
        }
    }

    public function test_al_completar_la_inicial_activa_el_contrato_y_vende_el_lote(): void
    {
        $this->pay('8000000.00');

        $this->assertSame(ContractStatus::PREVENTA_INACTIVA, $this->contract->fresh()->status);
        $this->assertSame(LotStatus::DISPONIBLE, $this->contract->lot->fresh()->status);
        $this->assertSame(AmortizationStatus::PARTIAL, $this->initialInstallment()->status);
        $this->assertSame('12000000.00', (string) $this->initialInstallment()->quota_debt);
        $this->assertSame(bcsub(self::SALE_PRICE, self::DOWN_PAYMENT, 2), (string) $this->initialInstallment()->remaining_balance);

        $this->pay('12000000.00');

        $this->assertSame(ContractStatus::ACTIVO, $this->contract->fresh()->status);
        $this->assertSame(LotStatus::VENDIDO, $this->contract->lot->fresh()->status);
        $this->assertSame(AmortizationStatus::PAID, $this->initialInstallment()->status);
        $this->assertSame('0.00', (string) $this->initialInstallment()->quota_debt);
        $this->assertSame(bcsub(self::SALE_PRICE, self::DOWN_PAYMENT, 2), (string) $this->initialInstallment()->remaining_balance);
    }

    private function pay(string $amount): void
    {
        app(DownPaymentService::class)->registerDownPayment(new CreateTransactionDTO(
            contractId: $this->contract->id,
            amount: $amount,
            transactionDate: Carbon::parse('2025-01-06'),
            paymentMethod: PaymentMethod::CASH,
            transactionType: TransactionType::DOWN_PAYMENT,
            installmentNumbers: [],
        ));
    }

    private function initialInstallment()
    {
        return $this->contract->amortizationInstallments()
            ->where('installment_number', 0)
            ->firstOrFail();
    }
}
