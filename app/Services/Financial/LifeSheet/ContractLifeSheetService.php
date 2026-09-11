<?php

namespace App\Services\Financial\LifeSheet;

use App\Enums\AllocationTarget;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\Transaction;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use App\Support\ReceiptNumber;
use App\Services\Financial\Refinancing\AcuerdoPagoService;
use App\Services\Financial\Refinancing\RefinanceContractService;
use Spatie\Activitylog\Models\Activity;

class ContractLifeSheetService
{
    public const NOTE = 'Saldo calculado sobre el valor total del plan, restando los pagos recibidos. No incluye recálculo de intereses por abonos anticipados.';

    public const AMORTIZATION_NOTE = 'Saldo de capital del plan francés. Los abonos anticipados sí recalculan intereses; no parte del valor futuro del plan.';

    public const SPECIAL_AMORTIZATION_NOTE = 'Saldo de capital pendiente del lote especial. No hay plan francés ni recálculo de intereses; ambas vistas parten del precio del lote.';

    public const GAP_LABEL = 'Brecha entre criterios';

    public const GAP_HINT = 'Saldo de la hoja de vida (valor futuro) − capital insoluto (amortización).';

    public function __construct(
        private readonly AmortizationCalculationService $calculationService,
    ) {}

    /**
     * @return array{
     *     header: array<string, mixed>,
     *     rows: list<array<string, mixed>>,
     *     summary: array<string, mixed>
     * }
     */
    public function build(Contract $contract): array
    {
        $contract->loadMissing([
            'lot', 'customer', 'customers', 'installments', 'paymentPromises',
            'transactions.allocations.installment',
        ]);

        $financed = $this->financedValue($contract);
        $quota = $this->monthlyQuota($contract);
        $outstandingCapital = $this->outstandingCapital($contract);
        $collected = '0.00';
        $rows = [];
        $running = $financed;

        foreach ($this->paymentsOf($contract) as $tx) {
            $amount = $this->money($tx->amount);
            $affectsRunning = $this->affectsRunningTotal($tx);
            if ($affectsRunning) {
                $collected = bcadd($collected, $amount, 2);
                $running = bcsub($running, $amount, 2);
            }
            $method = $tx->payment_method instanceof PaymentMethod
                ? $tx->payment_method
                : PaymentMethod::tryFrom((string) $tx->payment_method);
            $notes = (string) ($tx->notes ?? '');

            $rows[] = [
                'transaction_id' => $tx->id,
                'date' => $tx->transaction_date?->toDateString(),
                'concept' => $this->conceptFrom($notes, $tx),
                'receipt_number' => ReceiptNumber::fromStored($tx->receipt_number, $notes),
                'efectivo' => $method === PaymentMethod::CASH ? $amount : '0.00',
                'bancolombia' => $method === PaymentMethod::TRANSFER ? $amount : '0.00',
                'occidente' => $method === PaymentMethod::BANK ? $amount : '0.00',
                'amount' => $amount,
                'payment_method' => $method?->value,
                'total_paid' => $collected,
                'balance' => $running,
                'affects_running_total' => $affectsRunning,
                'notes' => $notes !== '' ? $notes : null,
                'allocations' => $this->allocationsOf($tx),
                'amortization_application' => $this->amortizationApplication($contract, $tx, $notes),
            ];
        }

        $gap = bcsub($running, $outstandingCapital, 2);
        $customer = $contract->primaryCustomer();
        $lot = $contract->lot;
        $area = $this->nullableMoney($lot?->area_m2);
        $priceM2 = $this->nullableMoney($lot?->price_m2);

        $header = [
            'lot_number' => $lot?->number,
            'sale_price' => $this->money($contract->sale_price),
            'financed_value' => $financed,
            'financed_value_basis' => $this->financedValueBasis($contract),
            'area_m2' => $area,
            'price_m2' => $priceM2,
            'down_payment' => $this->money($contract->down_payment_pactada),
            'monthly_quota' => $quota,
            'customer_name' => $contract->holderDisplayName(),
            'document_number' => $customer?->document_number,
            'address' => $this->blankToNull($customer?->address),
            'email' => $this->blankToNull($customer?->email),
            'phone' => $this->placeholderPhone($customer?->phone),
            'term_months' => (int) $contract->term_months,
            'seller_name' => $this->blankToNull($contract->seller_name),
            'is_special_lot' => (bool) $contract->is_special_lot,
            'is_custom_plan' => (bool) $contract->is_custom_plan,
            'note' => self::NOTE,
        ];

        $interestPaid = $this->interestPaid($contract);
        $principalPaid = $this->principalPaid($contract);

        $summary = [
            'collected' => $collected,
            'interest_paid' => $interestPaid,
            'principal_paid' => $principalPaid,
            'unimputed' => $this->unimputed($collected, $interestPaid, $principalPaid),
            'life_sheet_balance' => $running,
            'outstanding_capital' => $outstandingCapital,
            'criteria_gap' => $gap,
            'criteria_gap_label' => self::GAP_LABEL,
            'criteria_gap_hint' => self::GAP_HINT,
            'amortization_note' => $contract->is_special_lot ? self::SPECIAL_AMORTIZATION_NOTE : self::AMORTIZATION_NOTE,
            'life_sheet_note' => self::NOTE,
        ];

        return [
            'header' => $header,
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    public function financedValue(Contract $contract): string
    {
        if ($contract->is_special_lot) {
            return $this->money($contract->sale_price);
        }

        if ($this->usesCommercialPromiseFutureValue($contract)) {
            $promises = '0.00';
            foreach ($contract->paymentPromises as $promise) {
                if (trim((string) ($promise->description ?? '')) === AcuerdoPagoService::DESCRIPTION) {
                    continue;
                }
                $promises = bcadd($promises, $this->money($promise->expected_amount), 2);
            }

            return bcadd($this->money($contract->down_payment_pactada), $promises, 2);
        }

        $term = (int) $contract->term_months;
        if ($term <= 0) {
            return $this->money($contract->sale_price);
        }

        $principal = bcsub($this->money($contract->sale_price), $this->money($contract->down_payment_pactada), 2);
        if (bccomp($principal, '0.00', 2) < 0) {
            $principal = '0.00';
        }

        $pmt = $this->calculationService->calculateFixedQuota(
            $principal,
            $this->money($contract->interest_rate),
            $term,
        );

        return bcadd($this->money($contract->down_payment_pactada), bcmul($pmt, (string) $term, 2), 2);
    }

    /**
     * Interés ya cubierto, leído de la amortización. Las transacciones no
     * guardan desglose: la imputación interés→capital vive en las cuotas.
     */
    public function interestPaid(Contract $contract): string
    {
        $total = '0.00';

        foreach ($contract->installments as $row) {
            $total = bcadd($total, $this->money($row->interest_paid ?? '0'), 2);
        }

        return $total;
    }

    /** Capital ya abonado, incluida la cuota inicial (que es 100% capital). */
    public function principalPaid(Contract $contract): string
    {
        $total = '0.00';

        foreach ($contract->installments as $row) {
            $total = bcadd($total, $this->money($row->principal_paid ?? '0'), 2);
        }

        return $total;
    }

    /**
     * Dinero recaudado que todavía no aparece imputado a interés ni capital
     * (por ejemplo interés diferido). Se expone para que el consolidado cuadre
     * con el total pagado en lugar de mostrar una diferencia sin explicar.
     */
    private function unimputed(string $collected, string $interestPaid, string $principalPaid): string
    {
        $imputed = bcadd($interestPaid, $principalPaid, 2);
        $rest = bcsub($collected, $imputed, 2);

        return bccomp($rest, '0.00', 2) > 0 ? $rest : '0.00';
    }

    public function outstandingCapital(Contract $contract): string
    {
        $capital = '0.00';

        foreach ($contract->installments as $row) {
            $pending = bcsub(
                $this->money($row->principal_value ?? '0'),
                $this->money($row->principal_paid ?? '0'),
                2
            );
            if (bccomp($pending, '0.00', 2) > 0) {
                $capital = bcadd($capital, $pending, 2);
            }
        }

        return $capital;
    }

    private function monthlyQuota(Contract $contract): ?string
    {
        if ($contract->is_special_lot || $this->usesCommercialPromiseFutureValue($contract)) {
            return null;
        }

        $term = (int) $contract->term_months;
        if ($term <= 0) {
            return null;
        }

        $principal = bcsub($this->money($contract->sale_price), $this->money($contract->down_payment_pactada), 2);
        if (bccomp($principal, '0.00', 2) <= 0) {
            return null;
        }

        return $this->calculationService->calculateFixedQuota(
            $principal,
            $this->money($contract->interest_rate),
            $term,
        );
    }

    private function usesCommercialPromiseFutureValue(Contract $contract): bool
    {
        if (! $contract->is_custom_plan) {
            return false;
        }

        return ! $this->wasBalanceRefinanced($contract);
    }

    private function wasBalanceRefinanced(Contract $contract): bool
    {
        return Activity::query()
            ->where('log_name', RefinanceContractService::LOG_NAME)
            ->where('subject_type', $contract::class)
            ->where('subject_id', $contract->getKey())
            ->where('description', 'like', '%refinanciar_saldo%')
            ->exists();
    }

    private function financedValueBasis(Contract $contract): string
    {
        if ($contract->is_special_lot) {
            return 'sale_price';
        }

        if ($this->usesCommercialPromiseFutureValue($contract)) {
            return 'commercial_promises';
        }

        return 'french_pmt';
    }

    /**
     * Lista lo que se muestra en HV, incluida la trazabilidad de reversas.
     * El recaudo corrido se decide en {@see affectsRunningTotal()}.
     *
     * @return list<Transaction>
     */
    private function paymentsOf(Contract $contract): array
    {
        $excluded = [TransactionType::REFUND->value];

        return $contract->transactions
            ->filter(function (Transaction $tx) use ($excluded) {
                $type = $tx->transaction_type instanceof TransactionType
                    ? $tx->transaction_type->value
                    : (string) $tx->transaction_type;

                return ! in_array($type, $excluded, true);
            })
            ->sortBy([
                ['transaction_date', 'asc'],
                ['id', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * El par cobro revertido + fila de reversa se anula: no mueve Total Pagado
     * ni Saldo. Siguen visibles en la tabla para ver que "se pagó y se revirtió".
     */
    private function affectsRunningTotal(Transaction $tx): bool
    {
        if ($tx->isReversed()) {
            return false;
        }

        $type = $tx->transaction_type instanceof TransactionType
            ? $tx->transaction_type
            : TransactionType::tryFrom((string) $tx->transaction_type);

        return $type !== TransactionType::PAYMENT_REVERSAL;
    }

    private function conceptFrom(string $notes, Transaction $tx): string
    {
        $concept = $this->rawConceptFrom($notes, $tx);

        if ($this->affectsRunningTotal($tx)) {
            return $concept;
        }

        return $tx->isReversal()
            ? $concept.' (no afecta el saldo)'
            : $concept.' (revertido, no afecta el saldo)';
    }

    private function rawConceptFrom(string $notes, Transaction $tx): string
    {
        if (preg_match('/Concepto:\s*(.+?)(?:\s*\||$)/u', $notes, $match)) {
            return trim($match[1]);
        }

        $type = $tx->transaction_type instanceof TransactionType
            ? $tx->transaction_type
            : TransactionType::tryFrom((string) $tx->transaction_type);

        return match ($type) {
            TransactionType::DOWN_PAYMENT => 'CUOTA INICIAL',
            TransactionType::EXTRAORDINARY_PAYMENT => 'ABONO EXTRAORDINARIO',
            TransactionType::DEFERRED_INTEREST => 'INTERÉS DIFERIDO',
            TransactionType::RESIDUAL_COLLECTION => 'RESIDUALES MENORES',
            TransactionType::PAYMENT_REVERSAL => 'REVERSA DE PAGO',
            default => $this->conceptFromAllocations($tx)
                ?? ($type === TransactionType::SPLIT_PAYMENT ? 'CUOTA INICIAL + CUOTA' : 'PAGO'),
        };
    }

    private function conceptFromAllocations(Transaction $tx): ?string
    {
        $hasInicial = false;
        $hasCapital = false;
        $regulars = [];

        foreach ($tx->allocations as $allocation) {
            $target = $allocation->target instanceof AllocationTarget
                ? $allocation->target
                : AllocationTarget::tryFrom((string) $allocation->target);

            if ($target === AllocationTarget::DOWN_PAYMENT) {
                $hasInicial = true;
                continue;
            }

            if ($target === AllocationTarget::CAPITAL) {
                $hasCapital = true;
                continue;
            }

            if ($target === AllocationTarget::INSTALLMENT) {
                $number = $allocation->installment
                    ? (int) $allocation->installment->installment_number
                    : 0;
                if ($number > 0) {
                    $regulars[$number] = $number;
                }
            }
        }

        ksort($regulars);
        $parts = [];
        if ($hasInicial) {
            $parts[] = 'CUOTA INICIAL';
        }
        if ($regulars !== []) {
            $parts[] = 'CUOTA '.implode('-', array_values($regulars));
        }

        if ($parts !== []) {
            return implode(' + ', $parts);
        }

        return $hasCapital ? 'ABONO A CAPITAL' : null;
    }

    /**
     * Reparto de un pago que cubrió a la vez cuota inicial y cuotas regulares.
     * Vacío en los pagos de un solo destino.
     *
     * @return list<array<string, mixed>>
     */
    private function allocationsOf(Transaction $tx): array
    {
        return $tx->allocations
            ->sortBy('id')
            ->map(fn ($allocation) => [
                'target' => $allocation->target->value,
                'target_label' => $allocation->target->label(),
                'installment_number' => $allocation->installment
                    ? (int) $allocation->installment->installment_number
                    : null,
                'amount' => $this->money($allocation->amount),
                'principal' => $this->money($allocation->principal),
                'interest' => $this->money($allocation->interest),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{installment_number: int, principal_paid: string, interest_paid: string, extra_payment: string}>
     */
    private function amortizationApplication(Contract $contract, Transaction $tx, string $notes): array
    {
        $receipt = ReceiptNumber::fromStored($tx->receipt_number, $notes);
        if ($receipt === null) {
            return [];
        }

        $tokens = preg_split('/\s*-\s*/', $receipt) ?: [];
        $applied = [];

        foreach ($contract->installments as $row) {
            $rowReceipt = trim((string) ($row->receipt_number ?? ''));
            if ($rowReceipt === '') {
                continue;
            }

            $hit = $rowReceipt === $receipt;
            foreach ($tokens as $token) {
                if ($token !== '' && str_contains($rowReceipt, $token)) {
                    $hit = true;
                    break;
                }
            }

            if (! $hit) {
                continue;
            }

            $applied[] = [
                'installment_number' => (int) $row->installment_number,
                'principal_paid' => $this->money($row->principal_paid ?? '0'),
                'interest_paid' => $this->money($row->interest_paid ?? '0'),
                'extra_payment' => $this->money($row->extra_payment ?? '0'),
            ];
        }

        return $applied;
    }

    private function placeholderPhone(?string $phone): ?string
    {
        $value = $this->blankToNull($phone);
        if ($value === null || preg_match('/^0+$/', $value)) {
            return null;
        }

        return $value;
    }

    private function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $money = $this->money($value);

        return bccomp($money, '0.00', 2) === 0 ? null : $money;
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
    }
}
