<?php

namespace App\Services\Imports;

use App\DTOs\CreateTransactionDTO;
use App\Enums\AllocationTarget;
use App\Enums\AmortizationStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Imports\SanMiguel\SanMiguelPaymentConceptParser;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\ContractResidualBalance;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use App\Services\Financial\Transaction\InstallmentPaymentAllocator;
use App\Services\Residual\ResidualBalanceService;
use App\Support\DownPaymentLedger;
use App\Support\DueDateRules;
use App\Support\FinancialRules;
use Carbon\Carbon;

/**
 * Replay histórico SM: inicial → mora a payment_date → cuotas del concepto → reducir_plazo.
 * No usa la cola viva (suppressesRegularOverdue queda fuera).
 */
class SanMiguelConceptReplayService
{
    /** @var list<array<string, mixed>> */
    private array $traces = [];

    public function __construct(
        private readonly AmortizationCalculationService $calculationService,
        private readonly InstallmentPaymentAllocator $allocator,
        private readonly DownPaymentService $downPaymentService,
        private readonly ResidualBalanceService $residualBalanceService,
        private readonly SanMiguelPaymentConceptParser $parser,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function replay(Contract $contract, bool $collectTrace = false): array
    {
        $this->traces = [];
        $dueDates = $this->dueDates($contract);
        $overages = $this->resetInitialToDirectDownPayments($contract, $collectTrace);
        $this->restoreFrenchPlan($contract, $dueDates);
        ContractResidualBalance::query()->where('contract_id', $contract->id)->delete();

        foreach ($overages as $overage) {
            Carbon::setTestNow($overage['date']->copy()->endOfDay());
            try {
                $this->applyInicialOverage(
                    $contract->fresh(),
                    $overage['tx'],
                    $overage['overage'],
                    $overage['date'],
                );
            } finally {
                Carbon::setTestNow();
            }
        }

        $payments = $contract->transactions()
            ->whereIn('transaction_type', [
                TransactionType::REGULAR_PAYMENT->value,
                TransactionType::EXTRAORDINARY_PAYMENT->value,
            ])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($payments as $tx) {
            $date = Carbon::parse($tx->transaction_date)->startOfDay();
            Carbon::setTestNow($date->copy()->endOfDay());
            try {
                $this->applyPayment($contract->fresh(), $tx, $date, $collectTrace);
            } finally {
                Carbon::setTestNow();
            }
        }

        return $this->traces;
    }

    private function applyPayment(Contract $contract, Transaction $tx, Carbon $date, bool $collectTrace = false): void
    {
        $tx->allocations()->delete();
        $concept = $this->parser->parse((string) $tx->notes);
        $leftover = $this->money($tx->amount);
        $before = $this->regularSnapshots($contract);
        $touched = [];

        $overdueBefore = [];
        foreach ($this->calendarOverdue($contract, $date) as $row) {
            $overdueBefore[] = $this->quotaBrief($row);
        }

        $inicialApplied = '0.00';
        $inicialBefore = $this->quotaSnapshot($contract, 0);
        $leftoverAfterInicial = $this->applyInicialIfPending($contract, $tx, $date, $leftover);
        $inicialApplied = $this->money(bcsub($leftover, $leftoverAfterInicial, 2));
        $leftover = $leftoverAfterInicial;
        $contract = $contract->fresh();
        if (bccomp($inicialApplied, '0.00', 2) > 0) {
            $touched[0] = $this->quotaSnapshot($contract, 0);
        }

        $moraLines = [];
        $last = null;

        foreach ($this->calendarOverdue($contract, $date) as $installment) {
            if (bccomp($leftover, '0.00', 2) <= 0) {
                break;
            }
            $n = (int) $installment->installment_number;
            $beforePaid = [
                'interest' => $this->money($installment->interest_paid),
                'principal' => $this->money($installment->principal_paid),
            ];
            $allocation = $this->allocator->applyToInstallment($installment, $leftover, $date, $contract);
            $fresh = $installment->fresh();
            $interestDelta = $this->maxZero(bcsub($this->money($fresh->interest_paid), $beforePaid['interest'], 2));
            $principalDelta = $this->maxZero(bcsub($this->money($fresh->principal_paid), $beforePaid['principal'], 2));
            $moraLines[] = [
                'number' => $n,
                'applied' => $allocation['applied'],
                'interest' => $interestDelta,
                'principal' => $principalDelta,
            ];
            $leftover = $this->money(bcsub($leftover, $allocation['applied'], 2));
            $last = $fresh;
            $touched[$n] = $this->quotaSnapshot($contract, $n);
        }

        $namedLines = [];
        foreach ($concept->numbers as $number) {
            if (bccomp($leftover, '0.00', 2) <= 0) {
                break;
            }
            $installment = $contract->amortizationInstallments()
                ->where('installment_number', $number)
                ->first();
            if (! $installment || $this->isClosed($installment)) {
                continue;
            }
            $beforePaid = [
                'interest' => $this->money($installment->interest_paid),
                'principal' => $this->money($installment->principal_paid),
            ];
            $allocation = $this->allocator->applyToInstallment($installment, $leftover, $date, $contract);
            $fresh = $installment->fresh();
            $namedLines[] = [
                'number' => $number,
                'applied' => $allocation['applied'],
                'interest' => $this->maxZero(bcsub($this->money($fresh->interest_paid), $beforePaid['interest'], 2)),
                'principal' => $this->maxZero(bcsub($this->money($fresh->principal_paid), $beforePaid['principal'], 2)),
            ];
            $leftover = $this->money(bcsub($leftover, $allocation['applied'], 2));
            $last = $fresh;
            $touched[$number] = $this->quotaSnapshot($contract, $number);
        }

        $fallbackLines = [];
        if (! $last && FinancialRules::leftoverExceedsAbsorbedSurplus($leftover)) {
            $last = $contract->amortizationInstallments()
                ->where('installment_number', '>', 0)
                ->where('status', '!=', AmortizationStatus::PAID->value)
                ->orderBy('installment_number')
                ->first();
        }

        if ($last && FinancialRules::leftoverExceedsAbsorbedSurplus($leftover) && ! $this->isClosed($last)) {
            $beforePaid = [
                'interest' => $this->money($last->interest_paid),
                'principal' => $this->money($last->principal_paid),
            ];
            $allocation = $this->allocator->applyToInstallment($last, $leftover, $date, $contract);
            $fresh = $last->fresh();
            $fallbackLines[] = [
                'number' => (int) $fresh->installment_number,
                'applied' => $allocation['applied'],
                'interest' => $this->maxZero(bcsub($this->money($fresh->interest_paid), $beforePaid['interest'], 2)),
                'principal' => $this->maxZero(bcsub($this->money($fresh->principal_paid), $beforePaid['principal'], 2)),
            ];
            $leftover = $this->money(bcsub($leftover, $allocation['applied'], 2));
            $last = $fresh;
            $touched[(int) $fresh->installment_number] = $this->quotaSnapshot($contract, (int) $fresh->installment_number);
        }

        $extraApplied = '0.00';
        $extraOn = null;
        if ($last && FinancialRules::leftoverExceedsAbsorbedSurplus($leftover)) {
            $extraOn = (int) $last->installment_number;
            $extraApplied = $leftover;
            $this->applyExtraInPlace($contract, $last, $leftover);
            $leftover = '0.00';
            $touched[$extraOn] = $this->quotaSnapshot($contract, $extraOn);
        }

        $this->recordRegularDeltas($tx, $contract, $before);
        $residualBefore = $leftover;
        $this->recordLeftoverResidual($contract, $leftover, $last);
        $destino = 'ninguno (sin sobrante)';
        if (bccomp($extraApplied, '0.00', 2) > 0) {
            $destino = 'reducir_plazo en cuota #'.$extraOn;
        } elseif ($this->residualBalanceService->isMinorResidual($residualBefore)
            && FinancialRules::leftoverExceedsAbsorbedSurplus($residualBefore)
        ) {
            $destino = 'contract_residual_balances (menor a $5.000)';
        } elseif (bccomp($residualBefore, '0.00', 2) > 0) {
            $destino = 'absorbido como polvo (≤ $2)';
        }

        if ($collectTrace) {
            ksort($touched);
            $this->traces[] = [
                'kind' => 'regular',
                'date' => $date->toDateString(),
                'tx_id' => $tx->id,
                'receipt' => $this->receiptFromNotes((string) $tx->notes),
                'concept' => $this->parser->extractConcept((string) $tx->notes),
                'amount' => $this->money($tx->amount),
                'named' => $concept->numbers,
                'overdue_before' => $overdueBefore,
                'inicial_applied' => $inicialApplied,
                'inicial_before' => $inicialBefore,
                'mora' => $moraLines,
                'named_applied' => $namedLines,
                'fallback' => $fallbackLines,
                'extra' => $extraApplied,
                'extra_on' => $extraOn,
                'residual_leftover' => $residualBefore,
                'destino' => $destino,
                'touched' => array_values($touched),
            ];
        }
    }

    private function applyInicialIfPending(
        Contract $contract,
        Transaction $tx,
        Carbon $date,
        string $leftover,
    ): string {
        $pending = DownPaymentLedger::pending($contract->fresh());
        if (bccomp($pending, '0.00', 2) <= 0 || FinancialRules::residualIsWithinCompletionTolerance($pending)) {
            return $leftover;
        }
        if (! FinancialRules::leftoverExceedsAbsorbedSurplus($leftover)) {
            return $leftover;
        }

        $toInicial = bccomp($leftover, $pending, 2) === 1 ? $pending : $leftover;
        $initial = $contract->amortizationInstallments()
            ->where('installment_number', 0)
            ->first();

        TransactionAllocation::query()->create([
            'transaction_id' => $tx->id,
            'target' => AllocationTarget::DOWN_PAYMENT,
            'amortization_installment_id' => $initial?->id,
            'amount' => $toInicial,
            'principal' => $toInicial,
            'interest' => '0.00',
        ]);

        $method = $tx->payment_method instanceof PaymentMethod
            ? $tx->payment_method
            : (PaymentMethod::tryFrom((string) $tx->payment_method) ?? PaymentMethod::TRANSFER);

        $this->downPaymentService->applyExistingDownPaymentToSchedule(
            $contract,
            new CreateTransactionDTO(
                contractId: $contract->id,
                amount: $toInicial,
                transactionDate: $date,
                paymentMethod: $method,
                transactionType: TransactionType::DOWN_PAYMENT,
                installmentNumbers: [],
                notes: $tx->notes,
            )
        );

        return $this->money(bcsub($leftover, $toInicial, 2));
    }

    /**
     * Mora de calendario a la fecha del recibo. Ignora preventa.
     *
     * @return list<AmortizationInstallment>
     */
    private function calendarOverdue(Contract $contract, Carbon $date): array
    {
        $asOf = $date->toDateString();

        return $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->get()
            ->filter(function (AmortizationInstallment $row) use ($asOf) {
                if ($this->isClosed($row) || $row->due_date === null) {
                    return false;
                }

                return DueDateRules::isOverdue($row->due_date, $asOf);
            })
            ->values()
            ->all();
    }

    private function isClosed(AmortizationInstallment $installment): bool
    {
        $status = $installment->status instanceof AmortizationStatus
            ? $installment->status
            : AmortizationStatus::tryFrom((string) $installment->status);

        if ($status === AmortizationStatus::PAID) {
            return true;
        }

        $debt = $this->money($installment->quota_debt ?? '0.00');

        return FinancialRules::residualIsWithinCompletionTolerance($debt);
    }

    private function applyExtraInPlace(Contract $contract, AmortizationInstallment $installment, string $surplus): void
    {
        $extra = $this->money($surplus);
        $installment->update([
            'extra_payment' => $this->money(bcadd($this->money($installment->extra_payment), $extra, 2)),
            'principal_paid' => $this->money(bcadd($this->money($installment->principal_paid), $extra, 2)),
            'principal_value' => $this->money(bcadd($this->money($installment->principal_value), $extra, 2)),
            'remaining_balance' => $this->maxZero(bcsub($this->money($installment->remaining_balance), $extra, 2)),
            'projected_balance' => $this->maxZero(bcsub($this->money($installment->projected_balance), $extra, 2)),
            'status' => AmortizationStatus::PAID->value,
        ]);

        $this->calculationService->recalculateFutureKeepingQuota(
            $contract,
            (int) $installment->installment_number,
        );
    }

    private function recordLeftoverResidual(
        Contract $contract,
        string $leftover,
        ?AmortizationInstallment $last = null,
    ): void {
        if (! $this->residualBalanceService->isMinorResidual($leftover)) {
            return;
        }
        if (! FinancialRules::leftoverExceedsAbsorbedSurplus($leftover)) {
            return;
        }
        if (! $last) {
            $last = $contract->amortizationInstallments()
                ->where('installment_number', '>', 0)
                ->orderByDesc('installment_number')
                ->first();
        }
        if (! $last) {
            return;
        }

        $this->residualBalanceService->recordIfMinor(
            (int) $contract->id,
            (int) $last->id,
            $leftover,
            true,
        );
    }

    /**
     * @return array<int, string>
     */
    private function dueDates(Contract $contract): array
    {
        $dates = [];
        foreach ($contract->amortizationInstallments()->where('installment_number', '>', 0)->get() as $row) {
            $dates[(int) $row->installment_number] = Carbon::parse($row->due_date)->toDateString();
        }

        return $dates;
    }

    /**
     * @param  array<int, string>  $dueDates
     */
    private function restoreFrenchPlan(Contract $contract, array $dueDates): void
    {
        $byNumber = $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->get()
            ->keyBy(fn (AmortizationInstallment $row) => (int) $row->installment_number);

        foreach ($this->calculationService->buildSchedule($contract) as $row) {
            $number = (int) $row['installment_number'];
            if ($number === 0) {
                continue;
            }

            $installment = $byNumber->get($number);
            if (! $installment) {
                continue;
            }

            $installment->update([
                'due_date' => $dueDates[$number] ?? $row['due_date'],
                'installment_value' => $row['installment_value'],
                'extra_payment' => '0.00',
                'interest_value' => $row['interest_value'],
                'principal_value' => $row['principal_value'],
                'quota_debt' => $row['quota_debt'],
                'remaining_balance' => $row['remaining_balance'],
                'projected_balance' => $row['projected_balance'],
                'interest_paid' => '0.00',
                'principal_paid' => '0.00',
                'status' => AmortizationStatus::PENDING->value,
                'payment_date' => null,
            ]);
        }
    }

    /**
     * @return list<array{tx: Transaction, overage: string, date: Carbon}>
     */
    private function resetInitialToDirectDownPayments(Contract $contract, bool $collectTrace = false): array
    {
        $contract->transactions()
            ->whereIn('transaction_type', [
                TransactionType::REGULAR_PAYMENT->value,
                TransactionType::EXTRAORDINARY_PAYMENT->value,
            ])
            ->get()
            ->each(fn (Transaction $tx) => $tx->allocations()->delete());

        $pactada = $this->money($contract->down_payment_pactada);
        $running = '0.00';
        $overages = [];
        $downs = $contract->transactions()
            ->where('transaction_type', TransactionType::DOWN_PAYMENT->value)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($downs as $tx) {
            $pendingBefore = $this->maxZero(bcsub($pactada, $running, 2));
            $amount = $this->money($tx->amount);
            $applied = bccomp($amount, $pendingBefore, 2) === 1 ? $pendingBefore : $amount;
            $overage = $this->money(bcsub($amount, $applied, 2));
            $running = $this->money(bcadd($running, $applied, 2));
            if (bccomp($running, $pactada, 2) === 1) {
                $running = $pactada;
            }
            $pendingAfter = $this->maxZero(bcsub($pactada, $running, 2));
            if (FinancialRules::leftoverExceedsAbsorbedSurplus($overage)) {
                $overages[] = [
                    'tx' => $tx,
                    'overage' => $overage,
                    'date' => Carbon::parse($tx->transaction_date)->startOfDay(),
                ];
            }

            if ($collectTrace) {
                $this->traces[] = [
                    'kind' => 'down_payment',
                    'date' => Carbon::parse($tx->transaction_date)->toDateString(),
                    'tx_id' => $tx->id,
                    'receipt' => $this->receiptFromNotes((string) $tx->notes),
                    'concept' => $this->parser->extractConcept((string) $tx->notes),
                    'amount' => $amount,
                    'named' => [],
                    'overdue_before' => [],
                    'inicial_applied' => $applied,
                    'inicial_before' => [
                        'number' => 0,
                        'due' => null,
                        'status' => bccomp($pendingBefore, '0.00', 2) > 0 ? 'partial' : 'paid',
                        'quota_debt' => $pendingBefore,
                        'interest_paid' => '0.00',
                        'principal_paid' => $this->money(bcsub($pactada, $pendingBefore, 2)),
                        'extra_payment' => '0.00',
                    ],
                    'mora' => [],
                    'named_applied' => [],
                    'fallback' => [],
                    'extra' => '0.00',
                    'extra_on' => null,
                    'residual_leftover' => '0.00',
                    'destino' => 'cuota inicial',
                    'touched' => [[
                        'number' => 0,
                        'due' => null,
                        'status' => bccomp($pendingAfter, '0.00', 2) > 0 ? 'partial' : 'paid',
                        'quota_debt' => $pendingAfter,
                        'interest_paid' => '0.00',
                        'principal_paid' => $running,
                        'extra_payment' => '0.00',
                    ]],
                ];
            }
        }

        $direct = $running;
        $debt = $this->maxZero(bcsub($pactada, $direct, 2));
        $paid = bccomp($direct, $pactada, 2) === 1 ? $pactada : $direct;

        $initial = $contract->amortizationInstallments()
            ->where('installment_number', 0)
            ->first();
        if ($initial) {
            $initial->update([
                'principal_paid' => $paid,
                'quota_debt' => $debt,
                'status' => bccomp($debt, '0.00', 2) > 0
                    ? AmortizationStatus::PARTIAL->value
                    : AmortizationStatus::PAID->value,
            ]);
        }

        return $overages;
    }

    /**
     * Recibo INICIAL partido: el leftover regular del mismo recibo pasa a
     * down_payment. El caller decide si rejuega el plan o solo aplica el overage.
     *
     * @return list<array<string, mixed>>
     */
    public function foldInicialSplits(Contract $contract): array
    {
        $downReceipts = [];
        foreach ($contract->transactions()
            ->where('transaction_type', TransactionType::DOWN_PAYMENT->value)
            ->get() as $tx
        ) {
            $receipt = $this->receiptFromNotes((string) $tx->notes);
            if ($receipt !== '—') {
                $downReceipts[$receipt] = true;
            }
        }

        $folded = [];
        $regulars = $contract->transactions()
            ->where('transaction_type', TransactionType::REGULAR_PAYMENT->value)
            ->orderBy('id')
            ->get();

        foreach ($regulars as $tx) {
            $parsed = $this->parser->parse((string) $tx->notes);
            if ($parsed->kind !== 'inicial') {
                continue;
            }
            $receipt = $this->receiptFromNotes((string) $tx->notes);
            if ($receipt === '—' || ! isset($downReceipts[$receipt])) {
                continue;
            }

            $allocations = $tx->allocations()->with('installment')->get()->map(
                function (TransactionAllocation $row) {
                    $target = $row->target instanceof AllocationTarget
                        ? $row->target->value
                        : (string) $row->target;

                    return [
                        'target' => $target,
                        'installment_id' => $row->amortization_installment_id,
                        'installment_number' => $row->installment
                            ? (int) $row->installment->installment_number
                            : null,
                        'amount' => $this->money($row->amount),
                        'principal' => $this->money($row->principal),
                        'interest' => $this->money($row->interest),
                    ];
                }
            )->all();

            $tx->allocations()
                ->whereIn('target', [
                    AllocationTarget::INSTALLMENT->value,
                    AllocationTarget::CAPITAL->value,
                ])
                ->delete();
            $tx->update(['transaction_type' => TransactionType::DOWN_PAYMENT]);

            $folded[] = [
                'tx_id' => $tx->id,
                'receipt' => $receipt,
                'amount' => $this->money($tx->amount),
                'date' => Carbon::parse($tx->transaction_date)->toDateString(),
                'allocations' => $allocations,
            ];
        }

        return $folded;
    }

    /**
     * G2: pliega el leftover y aplica el overage sin rehacer extras posteriores.
     *
     * @return list<array<string, mixed>>
     */
    public function repairInicialSplitKeepingSchedule(Contract $contract): array
    {
        $folded = $this->foldInicialSplits($contract);
        foreach ($folded as $item) {
            foreach ($item['allocations'] as $allocation) {
                if (($allocation['target'] ?? '') !== AllocationTarget::INSTALLMENT->value) {
                    continue;
                }
                $this->reverseInstallmentAllocation($allocation);
            }
            $tx = Transaction::query()->find($item['tx_id']);
            if (! $tx) {
                continue;
            }
            $date = Carbon::parse($item['date'])->startOfDay();
            Carbon::setTestNow($date->copy()->endOfDay());
            try {
                $this->applyInicialOverage($contract->fresh(), $tx, $item['amount'], $date);
            } finally {
                Carbon::setTestNow();
            }
        }

        return $folded;
    }

    /**
     * Sobrante de un recibo INICIAL ya aplicado a #0 hasta la pactada:
     * mora de calendario → residual menor → sobre-pactada en #0.
     */
    public function applyInicialOverage(
        Contract $contract,
        Transaction $tx,
        string $overage,
        Carbon $date,
    ): string {
        $leftover = $this->money($overage);
        if (! FinancialRules::leftoverExceedsAbsorbedSurplus($leftover)) {
            $this->ensureDownPaymentAllocation($tx, $contract, '0.00');

            return $leftover;
        }

        $before = $this->regularSnapshots($contract);
        foreach ($this->calendarOverdue($contract->fresh(), $date) as $installment) {
            if (! FinancialRules::leftoverExceedsAbsorbedSurplus($leftover)) {
                break;
            }
            $allocation = $this->allocator->applyToInstallment($installment, $leftover, $date, $contract);
            $leftover = $this->money(bcsub($leftover, $this->money($allocation['applied']), 2));
        }
        $this->recordRegularDeltas($tx, $contract->fresh(), $before);

        if (! FinancialRules::leftoverExceedsAbsorbedSurplus($leftover)) {
            $this->ensureDownPaymentAllocation($tx, $contract, '0.00');

            return '0.00';
        }

        $initial = $contract->amortizationInstallments()
            ->where('installment_number', 0)
            ->first();

        if ($this->residualBalanceService->isMinorResidual($leftover) && $initial) {
            $this->residualBalanceService->recordIfMinor(
                (int) $contract->id,
                (int) $initial->id,
                $leftover,
                true,
            );
            $this->ensureDownPaymentAllocation($tx, $contract, $leftover);

            return '0.00';
        }

        if ($initial) {
            $initial->update([
                'principal_paid' => $this->money(bcadd($this->money($initial->principal_paid), $leftover, 2)),
                'quota_debt' => '0.00',
                'status' => AmortizationStatus::PAID->value,
            ]);
        }
        $this->ensureDownPaymentAllocation($tx, $contract, $leftover);

        return '0.00';
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnoseInicialSplit(Contract $contract): array
    {
        $foldedLike = [];
        $downReceipts = [];
        foreach ($contract->transactions()
            ->where('transaction_type', TransactionType::DOWN_PAYMENT->value)
            ->get() as $tx
        ) {
            $receipt = $this->receiptFromNotes((string) $tx->notes);
            if ($receipt !== '—') {
                $downReceipts[$receipt][] = $tx;
            }
        }

        foreach ($contract->transactions()
            ->where('transaction_type', TransactionType::REGULAR_PAYMENT->value)
            ->get() as $tx
        ) {
            $parsed = $this->parser->parse((string) $tx->notes);
            if ($parsed->kind !== 'inicial') {
                continue;
            }
            $receipt = $this->receiptFromNotes((string) $tx->notes);
            if ($receipt === '—' || ! isset($downReceipts[$receipt])) {
                continue;
            }
            $dpPart = '0.00';
            foreach ($downReceipts[$receipt] as $dp) {
                $dpPart = $this->money(bcadd($dpPart, $this->money($dp->amount), 2));
            }
            $leftover = $this->money($tx->amount);
            $date = Carbon::parse($tx->transaction_date)->toDateString();
            $first = $contract->amortizationInstallments()
                ->where('installment_number', 1)
                ->first();
            $mora = $first && $first->due_date
                ? DueDateRules::isOverdue($first->due_date, $date)
                : false;
            $allocs = $tx->allocations()->with('installment')->get()->map(
                fn (TransactionAllocation $row) => [
                    'n' => $row->installment
                        ? (int) $row->installment->installment_number
                        : null,
                    'amount' => $this->money($row->amount),
                    'principal' => $this->money($row->principal),
                    'interest' => $this->money($row->interest),
                ]
            )->all();

            $foldedLike[] = [
                'receipt' => $receipt,
                'hv_amount' => $this->money(bcadd($dpPart, $leftover, 2)),
                'dp_part' => $dpPart,
                'leftover' => $leftover,
                'leftover_tx_id' => $tx->id,
                'date' => $date,
                'mora_at_payment' => $mora,
                'allocations' => $allocs,
                'dust' => $this->residualBalanceService->isMinorResidual($leftover),
            ];
        }

        $initial = $this->quotaSnapshot($contract, 0);
        $first = $this->quotaSnapshot($contract, 1);

        return [
            'pactada' => $this->money($contract->down_payment_pactada),
            'splits' => $foldedLike,
            'inicial' => $initial,
            'cuota_1' => $first,
        ];
    }

    /**
     * @param  array{installment_id: mixed, amount: string, principal: string, interest: string}  $allocation
     */
    private function reverseInstallmentAllocation(array $allocation): void
    {
        $id = (int) ($allocation['installment_id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $row = AmortizationInstallment::query()->find($id);
        if (! $row) {
            return;
        }

        $interest = $this->money($allocation['interest']);
        $principal = $this->money($allocation['principal']);
        $amount = $this->money($allocation['amount']);
        $newInterest = $this->maxZero(bcsub($this->money($row->interest_paid), $interest, 2));
        $newPrincipal = $this->maxZero(bcsub($this->money($row->principal_paid), $principal, 2));
        $newDebt = $this->money(bcadd($this->money($row->quota_debt), $amount, 2));
        $closed = FinancialRules::residualIsWithinCompletionTolerance($newDebt);

        $row->update([
            'interest_paid' => $newInterest,
            'principal_paid' => $newPrincipal,
            'quota_debt' => $closed ? '0.00' : $newDebt,
            'status' => $closed
                ? AmortizationStatus::PAID->value
                : AmortizationStatus::PARTIAL->value,
        ]);
    }

    private function ensureDownPaymentAllocation(Transaction $tx, Contract $contract, string $amount): void
    {
        $initial = $contract->amortizationInstallments()
            ->where('installment_number', 0)
            ->first();
        $existing = $tx->allocations()
            ->where('target', AllocationTarget::DOWN_PAYMENT->value)
            ->first();
        $money = $this->money($amount);

        if ($existing) {
            if (bccomp($money, '0.00', 2) > 0) {
                $existing->update([
                    'amount' => $this->money(bcadd($this->money($existing->amount), $money, 2)),
                    'principal' => $this->money(bcadd($this->money($existing->principal), $money, 2)),
                ]);
            }

            return;
        }

        TransactionAllocation::query()->create([
            'transaction_id' => $tx->id,
            'target' => AllocationTarget::DOWN_PAYMENT,
            'amortization_installment_id' => $initial?->id,
            'amount' => $money,
            'principal' => $money,
            'interest' => '0.00',
        ]);
    }

    /**
     * @return array{number: int, due: ?string, debt: string, status: string}
     */
    private function quotaBrief(AmortizationInstallment $row): array
    {
        return [
            'number' => (int) $row->installment_number,
            'due' => $row->due_date ? Carbon::parse($row->due_date)->toDateString() : null,
            'debt' => $this->money($row->quota_debt),
            'status' => $row->status instanceof AmortizationStatus
                ? $row->status->value
                : (string) $row->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quotaSnapshot(Contract $contract, int $number): array
    {
        $row = $contract->amortizationInstallments()
            ->where('installment_number', $number)
            ->first();

        if (! $row) {
            return [
                'number' => $number,
                'due' => null,
                'status' => 'missing',
                'quota_debt' => '0.00',
                'interest_paid' => '0.00',
                'principal_paid' => '0.00',
                'extra_payment' => '0.00',
            ];
        }

        return [
            'number' => $number,
            'due' => $row->due_date ? Carbon::parse($row->due_date)->toDateString() : null,
            'status' => $row->status instanceof AmortizationStatus
                ? $row->status->value
                : (string) $row->status,
            'quota_debt' => $this->money($row->quota_debt),
            'interest_paid' => $this->money($row->interest_paid),
            'principal_paid' => $this->money($row->principal_paid),
            'extra_payment' => $this->money($row->extra_payment),
        ];
    }

    private function receiptFromNotes(string $notes): string
    {
        if (preg_match('/Recibo\s*#\s*([^\s|]+)/u', $notes, $match)) {
            return trim($match[1]);
        }

        return '—';
    }

    /**
     * @return array<int, array{principal: string, interest: string, extra: string}>
     */
    private function regularSnapshots(Contract $contract): array
    {
        return $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->get()
            ->mapWithKeys(fn (AmortizationInstallment $row) => [
                (int) $row->id => [
                    'principal' => $this->money($row->principal_paid),
                    'interest' => $this->money($row->interest_paid),
                    'extra' => $this->money($row->extra_payment),
                ],
            ])
            ->all();
    }

    /**
     * @param  array<int, array{principal: string, interest: string, extra: string}>  $before
     */
    private function recordRegularDeltas(Transaction $tx, Contract $contract, array $before): void
    {
        $rows = $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->orderBy('installment_number')
            ->get();

        foreach ($rows as $row) {
            $was = $before[(int) $row->id] ?? [
                'principal' => '0.00',
                'interest' => '0.00',
                'extra' => '0.00',
            ];
            $principal = $this->maxZero(bcsub($this->money($row->principal_paid), $was['principal'], 2));
            $interest = $this->maxZero(bcsub($this->money($row->interest_paid), $was['interest'], 2));
            $extra = $this->maxZero(bcsub($this->money($row->extra_payment), $was['extra'], 2));
            $applied = $this->money(bcadd(bcadd($principal, $interest, 2), $extra, 2));
            if (bccomp($applied, '0.00', 2) <= 0) {
                continue;
            }

            TransactionAllocation::recordInstallmentAndCapital(
                $tx->id,
                $row->id,
                $applied,
                $principal,
                $interest,
                $extra,
            );
        }
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function maxZero(string $value): string
    {
        return bccomp($value, '0.00', 2) < 0 ? '0.00' : $this->money($value);
    }
}
