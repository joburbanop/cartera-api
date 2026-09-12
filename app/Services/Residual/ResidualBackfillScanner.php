<?php

namespace App\Services\Residual;

use App\Enums\AllocationTarget;
use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\ContractResidualBalance;
use App\Models\TransactionAllocation;
use App\Support\DownPaymentLedger;
use App\Support\FinancialRules;
use Illuminate\Support\Collection;

/**
 * Camino B: reconstruye residuales menores ya condonados al cerrar la cuota.
 * Solo propone INSERT en contract_residual_balances. Nunca toca cuotas ni txs.
 *
 * Si no hay evidencia, no se inserta.
 */
class ResidualBackfillScanner
{
    public const DECISION_INSERT = 'insert';

    public const DECISION_OMIT = 'omit';

    public const OMIT_SIN_RESIDUAL = 'sin_residual';

    public const OMIT_NO_RECONSTRUIBLE = 'no_reconstruible';

    public const OMIT_YA_EXISTE = 'ya_existe';

    public const EVIDENCE_ALLOCATION_BOOK_MINUS_CASH = 'allocation_book_minus_cash';

    public const EVIDENCE_DOWN_PAYMENT_LEDGER = 'down_payment_ledger';

    public const EVIDENCE_ALLOCATION_EXACTO = 'allocation_exacto';

    public const EVIDENCE_ALLOCATION_CASH_CUBRE = 'allocation_cash_cubre_o_excede';

    public const EVIDENCE_GAP_EXCEDE_CLOSER = 'gap_excede_closer';

    public const EVIDENCE_SIN_ALLOCATIONS = 'sin_allocations';

    public const EVIDENCE_BOOK_INCONSISTENTE = 'book_inconsistente';

    public const EVIDENCE_CUOTA_NO_CERRADA = 'cuota_no_cerrada';

    public const EVIDENCE_YA_EXISTE = 'ya_existe';

    public function __construct(
        private readonly ResidualBalanceService $residualBalanceService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function scanSanMiguel(): array
    {
        $contracts = Contract::query()
            ->where('contract_number', 'like', 'SM-LOTE-%')
            ->whereNull('deleted_at')
            ->orderBy('contract_number')
            ->get();

        return $this->scan($contracts);
    }

    /**
     * @param  Collection<int, Contract>|iterable<Contract>  $contracts
     * @return list<array<string, mixed>>
     */
    public function scan(iterable $contracts): array
    {
        $rows = [];

        foreach ($contracts as $contract) {
            $installments = $contract->amortizationInstallments()
                ->orderBy('installment_number')
                ->get();

            foreach ($installments as $installment) {
                $status = $this->statusValue($installment);
                if ($status !== AmortizationStatus::PAID->value) {
                    continue;
                }

                $rows[] = $this->evaluate($contract, $installment);
            }
        }

        return $rows;
    }

    /**
     * @return array{
     *     decision: string,
     *     omit_reason: string,
     *     evidence_method: string,
     *     contract_id: int,
     *     contract_number: string,
     *     installment_id: int,
     *     installment_number: int,
     *     amount: string,
     *     cash_applied: string,
     *     book_alloc: string,
     *     book_paid: string,
     *     extra_payment: string,
     *     allocation_count: int,
     *     installment_value: string,
     *     quota_debt: string,
     *     notes: string
     * }
     */
    public function evaluate(Contract $contract, AmortizationInstallment $installment): array
    {
        $base = $this->baseRow($contract, $installment);

        if (ContractResidualBalance::query()
            ->where('amortization_installment_id', $installment->id)
            ->exists()
        ) {
            return $this->omit($base, self::OMIT_YA_EXISTE, self::EVIDENCE_YA_EXISTE, '0.00', 'Ya hay fila de residual para esta cuota.');
        }

        $quotaDebt = $this->money((string) ($installment->quota_debt ?? '0.00'));
        if (bccomp($quotaDebt, '0.00', 2) > 0) {
            return $this->omit(
                $base,
                self::OMIT_NO_RECONSTRUIBLE,
                self::EVIDENCE_CUOTA_NO_CERRADA,
                '0.00',
                'Marcada paid pero quota_debt > 0.',
            );
        }

        if ((int) $installment->installment_number === 0) {
            return $this->evaluateInicial($contract, $installment, $base);
        }

        return $this->evaluateRegular($installment, $base);
    }

    /**
     * Inserta solo filas con decision=insert. No actualiza cuotas ni transacciones.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function persistInserts(array $rows): int
    {
        $created = 0;

        foreach ($rows as $row) {
            if (($row['decision'] ?? '') !== self::DECISION_INSERT) {
                continue;
            }

            $recorded = $this->residualBalanceService->recordIfMinor(
                (int) $row['contract_id'],
                (int) $row['installment_id'],
                (string) $row['amount'],
                true,
            );

            if ($recorded !== null) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function evaluateInicial(Contract $contract, AmortizationInstallment $installment, array $base): array
    {
        $collected = DownPaymentLedger::collected($contract);
        $pending = DownPaymentLedger::pending($contract);
        $base['cash_applied'] = $collected;
        $base['book_alloc'] = $this->money((string) $contract->down_payment_pactada);
        $base['book_paid'] = $this->money(bcadd(
            $this->money((string) ($installment->principal_paid ?? '0.00')),
            $this->money((string) ($installment->interest_paid ?? '0.00')),
            2
        ));

        if (bccomp($pending, '0.00', 2) === 0) {
            return $this->omit(
                $base,
                self::OMIT_SIN_RESIDUAL,
                self::EVIDENCE_DOWN_PAYMENT_LEDGER,
                '0.00',
                'Inicial cerrada en exacto (pactada - recaudado = 0).',
            );
        }

        return $this->classifyLeftover(
            $base,
            $pending,
            self::EVIDENCE_DOWN_PAYMENT_LEDGER,
            'Inicial: down_payment_pactada - DownPaymentLedger::collected.',
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function evaluateRegular(AmortizationInstallment $installment, array $base): array
    {
        $allocations = TransactionAllocation::query()
            ->where('amortization_installment_id', $installment->id)
            ->where('target', AllocationTarget::INSTALLMENT->value)
            ->get();

        $base['allocation_count'] = $allocations->count();

        $cash = '0.00';
        $bookAlloc = '0.00';
        foreach ($allocations as $allocation) {
            $cash = $this->money(bcadd($cash, $this->money((string) $allocation->amount), 2));
            $bookAlloc = $this->money(bcadd($bookAlloc, $this->money((string) $allocation->principal), 2));
            $bookAlloc = $this->money(bcadd($bookAlloc, $this->money((string) $allocation->interest), 2));
        }

        $bookPaid = $this->money(bcadd(
            $this->money((string) ($installment->principal_paid ?? '0.00')),
            $this->money((string) ($installment->interest_paid ?? '0.00')),
            2
        ));

        $base['cash_applied'] = $cash;
        $base['book_alloc'] = $bookAlloc;
        $base['book_paid'] = $bookPaid;

        if ($allocations->isEmpty()) {
            return $this->omit(
                $base,
                self::OMIT_NO_RECONSTRUIBLE,
                self::EVIDENCE_SIN_ALLOCATIONS,
                '0.00',
                'Cuota pagada sin transaction_allocations de target=installment.',
            );
        }

        if (bccomp($bookAlloc, $bookPaid, 2) !== 0) {
            if (bccomp($cash, $bookAlloc, 2) >= 0) {
                return $this->omit(
                    $base,
                    self::OMIT_SIN_RESIDUAL,
                    self::EVIDENCE_ALLOCATION_CASH_CUBRE,
                    '0.00',
                    'El efectivo cubre el libro de allocations; principal_paid incluye extra u otro ajuste. No hay residual.',
                );
            }

            return $this->omit(
                $base,
                self::OMIT_NO_RECONSTRUIBLE,
                self::EVIDENCE_BOOK_INCONSISTENTE,
                '0.00',
                'SUM(principal+interest) de allocations ≠ principal_paid+interest_paid y el efectivo no cubre el libro de allocations. No se inventa.',
            );
        }

        $gap = $this->money(bcsub($bookAlloc, $cash, 2));

        if (bccomp($gap, '0.00', 2) <= 0) {
            $method = bccomp($gap, '0.00', 2) === 0
                ? self::EVIDENCE_ALLOCATION_EXACTO
                : self::EVIDENCE_ALLOCATION_CASH_CUBRE;

            return $this->omit(
                $base,
                self::OMIT_SIN_RESIDUAL,
                $method,
                '0.00',
                $method === self::EVIDENCE_ALLOCATION_EXACTO
                    ? 'SUM(amount) = SUM(principal+interest) de allocations.'
                    : 'SUM(amount) > SUM(principal+interest); no hay faltante condonado.',
            );
        }

        return $this->classifyLeftover(
            $base,
            $gap,
            self::EVIDENCE_ALLOCATION_BOOK_MINUS_CASH,
            'Regular: SUM(principal+interest) − SUM(amount) de allocations installment; coincide con columnas paid.',
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function classifyLeftover(array $base, string $leftover, string $evidence, string $notes): array
    {
        if (! FinancialRules::residualIsWithinCompletionTolerance($leftover)) {
            return $this->omit(
                $base,
                self::OMIT_NO_RECONSTRUIBLE,
                self::EVIDENCE_GAP_EXCEDE_CLOSER,
                '0.00',
                'Faltante ≥ $500: el closer no lo habría condonado. No se inventa.',
            );
        }

        if (! $this->residualBalanceService->isMinorResidual($leftover)) {
            return $this->omit(
                $base,
                self::OMIT_NO_RECONSTRUIBLE,
                self::EVIDENCE_GAP_EXCEDE_CLOSER,
                '0.00',
                'Faltante fuera del techo de residual menor. No se inserta.',
            );
        }

        return $this->insert($base, $leftover, $evidence, $notes);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRow(Contract $contract, AmortizationInstallment $installment): array
    {
        return [
            'decision' => self::DECISION_OMIT,
            'omit_reason' => '',
            'evidence_method' => '',
            'contract_id' => (int) $contract->id,
            'contract_number' => (string) $contract->contract_number,
            'installment_id' => (int) $installment->id,
            'installment_number' => (int) $installment->installment_number,
            'amount' => '0.00',
            'cash_applied' => '0.00',
            'book_alloc' => '0.00',
            'book_paid' => '0.00',
            'extra_payment' => $this->money((string) ($installment->extra_payment ?? '0.00')),
            'allocation_count' => 0,
            'installment_value' => $this->money((string) ($installment->installment_value ?? '0.00')),
            'quota_debt' => $this->money((string) ($installment->quota_debt ?? '0.00')),
            'notes' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function insert(array $base, string $amount, string $evidence, string $notes): array
    {
        $base['decision'] = self::DECISION_INSERT;
        $base['omit_reason'] = '';
        $base['evidence_method'] = $evidence;
        $base['amount'] = $this->money($amount);
        $base['notes'] = $notes;

        return $base;
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function omit(array $base, string $reason, string $evidence, string $amount, string $notes): array
    {
        $base['decision'] = self::DECISION_OMIT;
        $base['omit_reason'] = $reason;
        $base['evidence_method'] = $evidence;
        $base['amount'] = $this->money($amount);
        $base['notes'] = $notes;

        return $base;
    }

    private function statusValue(AmortizationInstallment $installment): string
    {
        $status = $installment->status;

        return $status instanceof AmortizationStatus ? $status->value : (string) $status;
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
