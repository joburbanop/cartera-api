<?php

namespace App\Console\Commands;

use App\DTOs\CreateTransactionDTO;
use App\Enums\AllocationTarget;
use App\Enums\AmortizationStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Services\Collection\CascadeCollectionService;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use App\Services\Financial\LifeSheet\ContractLifeSheetService;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use App\Services\Financial\Transaction\InstallmentPaymentAllocator;
use App\Services\PaymentPromiseStatusService;
use App\Support\DownPaymentLedger;
use App\Support\FinancialRules;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReimputeSanMiguelTargetLotsCommand extends Command
{
    protected $signature = 'san-miguel:reimpute-target-lots {--dry-run : Solo muestra before/after sin escribir} {--lot=* : Solo estos lotes}';

    protected $description = 'Reimputa extras, unimputed, parcial, inicial+capital y tasa 0 en lotes San Miguel 3, 4, 5, 6, 7 y 11. Idempotente.';

    private const LOTS = ['3', '4', '5', '6', '7', '11'];

    public function __construct(
        private readonly AmortizationCalculationService $calculationService,
        private readonly InstallmentPaymentAllocator $allocator,
        private readonly CascadeCollectionService $cascadeCollectionService,
        private readonly ContractLifeSheetService $lifeSheetService,
        private readonly PaymentPromiseStatusService $promiseStatusService,
        private readonly DownPaymentService $downPaymentService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $lots = $this->selectedLots();
        $this->info($dryRun
            ? 'Modo --dry-run: no se escribe nada.'
            : 'Reimputación real de lotes '.implode(', ', $lots).'.');

        foreach ($lots as $lotNumber) {
            $contract = $this->findContract($lotNumber);
            if (! $contract) {
                $this->warn("Lote {$lotNumber}: no hay contrato SM-LOTE-{$lotNumber}.");

                continue;
            }

            $before = $this->snapshot($contract);
            $this->line('');
            $this->info("=== Lote {$lotNumber} ({$contract->contract_number}) BEFORE ===");
            $this->line($before);

            if (! $dryRun) {
                DB::transaction(function () use ($contract, $lotNumber) {
                    match ($lotNumber) {
                        '3' => $this->repairLot3($contract->fresh()),
                        '4' => $this->repairLot4($contract->fresh()),
                        '5' => $this->repairLot5($contract->fresh()),
                        '6' => $this->info('Lote 6: collected y promesas intactos. Sin escritura.'),
                        '7' => $this->repairLot7($contract->fresh()),
                        '11' => $this->repairLot11($contract->fresh()),
                        default => null,
                    };
                });
            }

            $after = $this->snapshot($contract->fresh()->load(['installments', 'transactions', 'paymentPromises', 'lot']));
            $this->info("=== Lote {$lotNumber} AFTER ===");
            $this->line($after);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function selectedLots(): array
    {
        $requested = array_map('strval', (array) $this->option('lot'));
        if ($requested === []) {
            return self::LOTS;
        }

        return array_values(array_intersect(self::LOTS, $requested));
    }

    private function findContract(string $lotNumber): ?Contract
    {
        return Contract::query()
            ->with(['installments', 'transactions', 'paymentPromises', 'lot'])
            ->where('contract_number', 'SM-LOTE-'.$lotNumber)
            ->whereNull('deleted_at')
            ->latest('id')
            ->first();
    }

    private function snapshot(Contract $contract): string
    {
        $summary = $this->lifeSheetService->build($contract)['summary'];
        $regular = $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->orderBy('installment_number')
            ->get();
        $extras = $regular
            ->filter(fn (AmortizationInstallment $row) => bccomp((string) $row->extra_payment, '0.00', 2) > 0)
            ->map(fn (AmortizationInstallment $row) => $row->installment_number.':'.$row->extra_payment)
            ->implode(', ');
        $collected = $contract->transactions->sum(fn (Transaction $tx) => (float) $tx->amount);
        $quota = optional($regular->first())->installment_value;
        $promises = $this->promiseStatusService->decorate($contract, $contract->paymentPromises);
        $promiseBrief = $promises->take(4)->map(function ($promise) {
            return '#'.$promise->payment_number.' '.$promise->status.' rem='.$promise->remaining_amount;
        })->implode(' | ');

        return implode("\n", [
            "contract_id={$contract->id} term={$contract->term_months} rate={$contract->interest_rate} collected={$collected}",
            "quota1={$quota} extras=[{$extras}]",
            'life_sheet unimputed='.$summary['unimputed'].' principal_paid='.$summary['principal_paid'].' interest_paid='.$summary['interest_paid'],
            'promises: '.$promiseBrief,
        ]);
    }

    private function repairLot3(Contract $contract): void
    {
        $this->resetInitialToDirectDownPayments($contract);
        $dueDates = $this->dueDates($contract);
        $this->restoreFrenchPlan($contract, $dueDates);
        $this->reapplyRegularPayments($contract, function (Transaction $tx): array {
            $concept = strtoupper((string) $tx->notes);
            if (preg_match('/CONCEPTO:\s*CUOTA 1\b/', $concept)) {
                return ['mode' => 'inicial_then_capital', 'numbers' => [1]];
            }

            return ['mode' => 'cascade'];
        });
    }

    private function repairLot4(Contract $contract): void
    {
        $dueDates = $this->dueDates($contract);
        $this->restoreFrenchPlan($contract, $dueDates);
        $this->reapplyRegularPayments($contract, function (Transaction $tx): array {
            $concept = strtoupper((string) $tx->notes);
            if (str_contains($concept, 'CUOTA 2-3') || str_contains($concept, 'CUOTA 2 - 3')) {
                return ['mode' => 'quotas_then_capital', 'numbers' => [2, 3]];
            }
            if (preg_match('/CONCEPTO:\s*CUOTA 1\b/', $concept)) {
                return ['mode' => 'quotas_then_capital', 'numbers' => [1]];
            }

            return ['mode' => 'cascade'];
        });
    }

    private function repairLot5(Contract $contract): void
    {
        $regular = $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->get();

        foreach ($regular as $row) {
            $extra = $this->money($row->extra_payment);
            if (! FinancialRules::leftoverExceedsAbsorbedSurplus($extra)) {
                continue;
            }

            $principalPaid = $this->money($row->principal_paid);
            $principalValue = $this->money($row->principal_value);
            $gap = $this->money(bcsub($principalValue, $principalPaid, 2));
            if (bccomp($gap, '0.00', 2) <= 0) {
                continue;
            }

            $add = bccomp($gap, $extra, 2) <= 0 ? $gap : $extra;
            $row->update([
                'principal_paid' => $this->money(bcadd($principalPaid, $add, 2)),
            ]);
        }

        $contract->unsetRelation('installments');
        $unimputed = $this->lifeSheetService->build($contract->fresh(['installments', 'transactions']))['summary']['unimputed'];
        if (bccomp($unimputed, '0.00', 2) > 0 && ! FinancialRules::leftoverExceedsAbsorbedSurplus($unimputed)) {
            $lastExtra = $contract->amortizationInstallments()
                ->where('installment_number', '>', 0)
                ->where('extra_payment', '>', 0)
                ->orderByDesc('installment_number')
                ->first();
            if ($lastExtra) {
                $lastExtra->update([
                    'principal_paid' => $this->money(bcadd($this->money($lastExtra->principal_paid), $unimputed, 2)),
                ]);
            }
        }
    }

    private function repairLot7(Contract $contract): void
    {
        $dueDates = $this->dueDates($contract);
        $regular = $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->orderBy('installment_number')
            ->get();

        foreach ($regular as $row) {
            $n = (int) $row->installment_number;
            $extra = $this->money($row->extra_payment);
            if ($n >= 1 && $n <= 15 && $this->isGarbageExtra($extra)) {
                $row->update(['extra_payment' => '0.00']);
            }
        }

        $this->restoreFrenchPlan($contract, $dueDates);
        $this->reapplyRegularPayments($contract, fn () => ['mode' => 'quotas_then_capital_next']);
    }

    private function repairLot11(Contract $contract): void
    {
        $dueDates = $this->dueDates($contract);
        $contract->update([
            'interest_rate' => FinancialRules::effectiveInterestRate((int) $contract->term_months, 0.0),
        ]);
        $contract->refresh();

        $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->delete();

        foreach ($this->calculationService->buildSchedule($contract) as $row) {
            if ((int) $row['installment_number'] === 0) {
                continue;
            }

            $number = (int) $row['installment_number'];
            $contract->amortizationInstallments()->create([
                ...$row,
                'due_date' => $dueDates[$number] ?? $row['due_date'],
                'interest_paid' => '0.00',
                'principal_paid' => '0.00',
                'payment_date' => null,
            ]);
        }

        $this->reapplyRegularPayments($contract, fn () => ['mode' => 'cascade']);
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
     * @param  callable(Transaction): array{mode: string, numbers?: list<int>}  $strategy
     */
    private function reapplyRegularPayments(Contract $contract, callable $strategy): void
    {
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
            $plan = $strategy($tx);
            Carbon::setTestNow($date->copy()->endOfDay());
            try {
                $tx->allocations()->delete();
                if (($plan['mode'] ?? '') === 'inicial_then_capital') {
                    $this->applyInicialThenCapital($contract, $tx, $date, $plan['numbers'] ?? [1]);
                } elseif (($plan['mode'] ?? '') === 'quotas_then_capital') {
                    $this->applyToNumbersThenCapitalAndRecord($contract, $tx, $date, $plan['numbers'] ?? []);
                } elseif (($plan['mode'] ?? '') === 'quotas_then_capital_next') {
                    $this->applyToNextThenCapitalAndRecord($contract, $tx, $date);
                } else {
                    $this->cascadeCollectionService->process(
                        $contract->id,
                        $this->money($tx->amount),
                        'adelantar_cuotas',
                        $date,
                        [],
                        null,
                        $tx->payment_method,
                        $tx->notes,
                        false,
                        $tx->id,
                    );
                }
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    /**
     * @param  list<int>  $numbers
     */
    private function applyToNumbersThenCapitalAndRecord(
        Contract $contract,
        Transaction $tx,
        Carbon $date,
        array $numbers,
    ): void {
        $before = $this->regularSnapshots($contract);
        $this->applyToNumbersThenCapital($contract, $this->money($tx->amount), $date, $numbers);
        $this->recordRegularDeltas($tx, $contract, $before);
    }

    private function applyToNextThenCapitalAndRecord(Contract $contract, Transaction $tx, Carbon $date): void
    {
        $before = $this->regularSnapshots($contract);
        $this->applyToNextThenCapital($contract, $this->money($tx->amount), $date);
        $this->recordRegularDeltas($tx, $contract, $before);
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
            $applied = $this->money(bcadd($principal, $interest, 2));
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

    /**
     * Faltante de inicial primero; el sobrante va a la cuota pedida y, si queda, a capital.
     * El banco sigue viendo un solo movimiento: el reparto queda en allocations.
     *
     * @param  list<int>  $numbers
     */
    private function applyInicialThenCapital(Contract $contract, Transaction $tx, Carbon $date, array $numbers): void
    {
        $tx->allocations()->delete();
        $leftover = $this->money($tx->amount);
        $pending = DownPaymentLedger::pending($contract->fresh());

        if (bccomp($pending, '0.00', 2) > 0 && ! FinancialRules::residualIsWithinCompletionTolerance($pending)) {
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

            $leftover = $this->money(bcsub($leftover, $toInicial, 2));
        }

        if (! FinancialRules::leftoverExceedsAbsorbedSurplus($leftover)) {
            return;
        }

        $before = $contract->amortizationInstallments()
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

        $this->applyToNumbersThenCapital($contract, $leftover, $date, $numbers);

        $installment = $contract->amortizationInstallments()
            ->whereIn('installment_number', $numbers)
            ->orderBy('installment_number')
            ->first();
        $snapshot = $before[(int) ($installment?->id ?? 0)] ?? [
            'principal' => '0.00',
            'interest' => '0.00',
            'extra' => '0.00',
        ];
        $fresh = $installment?->fresh();
        $principal = $this->maxZero(bcsub($this->money($fresh?->principal_paid), $snapshot['principal'], 2));
        $interest = $this->maxZero(bcsub($this->money($fresh?->interest_paid), $snapshot['interest'], 2));
        $extra = $this->maxZero(bcsub($this->money($fresh?->extra_payment), $snapshot['extra'], 2));

        TransactionAllocation::recordInstallmentAndCapital(
            $tx->id,
            $installment?->id,
            $leftover,
            $principal,
            $interest,
            $extra,
        );
    }

    private function resetInitialToDirectDownPayments(Contract $contract): void
    {
        $contract->transactions()
            ->whereIn('transaction_type', [
                TransactionType::REGULAR_PAYMENT->value,
                TransactionType::EXTRAORDINARY_PAYMENT->value,
            ])
            ->get()
            ->each(fn (Transaction $tx) => $tx->allocations()->delete());

        $direct = $this->money($contract->transactions()
            ->where('transaction_type', TransactionType::DOWN_PAYMENT->value)
            ->sum('amount'));
        $pactada = $this->money($contract->down_payment_pactada);
        $debt = $this->maxZero(bcsub($pactada, $direct, 2));
        $paid = bccomp($direct, $pactada, 2) === 1 ? $pactada : $direct;

        $initial = $contract->amortizationInstallments()
            ->where('installment_number', 0)
            ->first();
        if (! $initial) {
            return;
        }

        $initial->update([
            'principal_paid' => $paid,
            'quota_debt' => $debt,
            'status' => bccomp($debt, '0.00', 2) > 0
                ? AmortizationStatus::PARTIAL->value
                : AmortizationStatus::PAID->value,
        ]);
    }

    /**
     * @param  list<int>  $numbers
     */
    private function applyToNumbersThenCapital(Contract $contract, string $amount, Carbon $date, array $numbers): void
    {
        $leftover = $amount;
        $last = null;

        foreach ($numbers as $number) {
            $installment = $contract->amortizationInstallments()
                ->where('installment_number', $number)
                ->first();
            if (! $installment || bccomp($leftover, '0.00', 2) <= 0) {
                continue;
            }

            $allocation = $this->allocator->applyToInstallment($installment, $leftover, $date, $contract);
            $leftover = $this->money(bcsub($leftover, $allocation['applied'], 2));
            $last = $installment->fresh();
        }

        if ($last && FinancialRules::leftoverExceedsAbsorbedSurplus($leftover)) {
            $this->applyExtraInPlace($contract, $last, $leftover);
        }
    }

    private function applyToNextThenCapital(Contract $contract, string $amount, Carbon $date): void
    {
        $next = $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->orderBy('installment_number')
            ->first();

        if (! $next) {
            return;
        }

        $this->applyToNumbersThenCapital($contract, $amount, $date, [(int) $next->installment_number]);
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
        ]);

        $contract->amortizationInstallments()
            ->where('installment_number', '>', (int) $installment->installment_number)
            ->orderBy('installment_number')
            ->get()
            ->each(function (AmortizationInstallment $row) use ($extra) {
                $row->update([
                    'remaining_balance' => $this->maxZero(bcsub($this->money($row->remaining_balance), $extra, 2)),
                    'projected_balance' => $this->maxZero(bcsub($this->money($row->projected_balance), $extra, 2)),
                ]);
            });
    }

    private function isGarbageExtra(string $extra): bool
    {
        if (bccomp($extra, '0.00', 2) <= 0) {
            return false;
        }

        if (bccomp($extra, '374.00', 2) === 0 || bccomp($extra, '373.91', 2) === 0) {
            return false;
        }

        return true;
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
