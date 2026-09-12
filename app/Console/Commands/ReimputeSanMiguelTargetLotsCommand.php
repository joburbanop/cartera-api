<?php

namespace App\Console\Commands;

use App\DTOs\CreateTransactionDTO;
use App\Enums\AllocationTarget;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\ContractResidualBalance;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Services\Collection\CascadeCollectionService;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use App\Services\Financial\LifeSheet\ContractLifeSheetService;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use App\Services\Financial\Transaction\InstallmentPaymentAllocator;
use App\Services\Imports\SanMiguelConceptReplayService;
use App\Services\PaymentPromiseStatusService;
use App\Support\DownPaymentLedger;
use App\Support\FinancialRules;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReimputeSanMiguelTargetLotsCommand extends Command
{
    protected $signature = 'san-miguel:reimpute-target-lots
        {--dry-run : Solo muestra before/after sin escribir}
        {--lot=* : Solo estos lotes}
        {--wave= : Oleada (ad = G1 + Lote 3; bc = G3 + G4; inicial = recibo inicial partido)}';

    protected $description = 'Reimputa extras, unimputed, parcial, inicial+capital y tasa 0 en lotes San Miguel 3, 4, 5, 6, 7 y 11. Idempotente.';

    private const LOTS = ['3', '4', '5', '6', '7', '11'];

    /** G1 + re-pase Lote 3 (oleada A+D). */
    public const WAVE_AD_LOTS = ['3', '12', '17', '24', '25', '27', '33', '34', '57'];

    /** G3: rango + ABONO en el mismo concepto. */
    public const WAVE_B_LOTS = ['18', '22', '41'];

    /** G4: CUOTA N simple, monto > cuota → reducir_plazo. */
    public const WAVE_C_LOTS = ['35', '42', '55'];

    /** Oleadas B+C juntas (mismo protocolo, un solo dry-run). */
    public const WAVE_BC_LOTS = ['18', '22', '41', '35', '42', '55'];

    /** Recibo INICIAL cortado al tope de pactada. */
    public const WAVE_INICIAL_LOTS = ['34', '17', '42', '45', '6', '18', '13', '14', '19', '55', '56'];

    /** G2: no rejugamos extras; solo pliegue del leftover de inicial. */
    public const WAVE_INICIAL_NO_REPLAY = ['56'];

    public function __construct(
        private readonly AmortizationCalculationService $calculationService,
        private readonly InstallmentPaymentAllocator $allocator,
        private readonly CascadeCollectionService $cascadeCollectionService,
        private readonly ContractLifeSheetService $lifeSheetService,
        private readonly PaymentPromiseStatusService $promiseStatusService,
        private readonly DownPaymentService $downPaymentService,
        private readonly SanMiguelConceptReplayService $conceptReplayService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $wave = (string) $this->option('wave');
        if ($wave === 'inicial') {
            return $this->handleInicialWave();
        }
        if (in_array($wave, ['ad', 'bc'], true)) {
            return $this->handleConceptWave($wave);
        }

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

    private function handleConceptWave(string $wave): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $lots = $this->selectedConceptWaveLots($wave);
        $label = $wave === 'bc' ? 'B+C' : 'A+D';
        $portfolioBefore = $this->sanMiguelPortfolioTotals();

        $this->info($dryRun
            ? "Oleada {$label} dry-run: aplica en transacción y hace rollback."
            : "Oleada {$label} persistente de lotes ".implode(', ', $lots).'.');

        $report = [
            'dry_run' => $dryRun,
            'wave' => $wave,
            'lots' => $lots,
            'portfolio_before' => $portfolioBefore,
            'lot_diffs' => [],
            'collateral' => [],
        ];

        $apply = function () use ($lots, &$report): void {
            foreach ($lots as $lotNumber) {
                $contract = $this->findContract($lotNumber);
                if (! $contract) {
                    $this->warn("Lote {$lotNumber}: no hay contrato SM-LOTE-{$lotNumber}.");

                    continue;
                }

                $before = $this->detailedSnapshot($contract);
                $this->conceptReplayService->replay($contract->fresh());
                $after = $this->detailedSnapshot(
                    $contract->fresh()->load(['installments', 'transactions', 'paymentPromises', 'lot'])
                );
                $diff = $this->diffSnapshots($lotNumber, $before, $after);
                $report['lot_diffs'][] = $diff;
                foreach ($diff['status_flips'] as $flip) {
                    $report['collateral'][] = $flip;
                }
            }
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $apply();
                $report['portfolio_after'] = $this->sanMiguelPortfolioTotals();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($apply);
            $report['portfolio_after'] = $this->sanMiguelPortfolioTotals();
        }

        $path = app()->environment('testing')
            ? storage_path('app/testing-sm-wave-'.$wave.'-dry-run.json')
            : storage_path('app/sm-wave-'.$wave.'-dry-run.json');
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->printConceptWaveReport($report);
        $this->info("JSON: {$path}");

        return self::SUCCESS;
    }

    private function handleInicialWave(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $lots = $this->selectedInicialLots();
        $portfolioBefore = $this->sanMiguelPortfolioTotals();

        $this->info($dryRun
            ? 'Oleada inicial dry-run: aplica en transacción y hace rollback.'
            : 'Oleada inicial persistente de lotes '.implode(', ', $lots).'.');

        $report = [
            'dry_run' => $dryRun,
            'wave' => 'inicial',
            'lots' => $lots,
            'portfolio_before' => $portfolioBefore,
            'lot_diffs' => [],
            'collateral' => [],
        ];

        $apply = function () use ($lots, &$report): void {
            foreach ($lots as $lotNumber) {
                $contract = $this->findContract($lotNumber);
                if (! $contract) {
                    $this->warn("Lote {$lotNumber}: no hay contrato SM-LOTE-{$lotNumber}.");

                    continue;
                }

                $diagnosis = $this->conceptReplayService->diagnoseInicialSplit($contract);
                $before = $this->detailedSnapshot($contract);
                $skipReplay = in_array($lotNumber, self::WAVE_INICIAL_NO_REPLAY, true);
                if ($skipReplay) {
                    $folded = $this->conceptReplayService->repairInicialSplitKeepingSchedule($contract->fresh());
                } else {
                    $folded = $this->conceptReplayService->foldInicialSplits($contract->fresh());
                    $this->conceptReplayService->replay($contract->fresh());
                }
                $after = $this->detailedSnapshot(
                    $contract->fresh()->load(['installments', 'transactions', 'paymentPromises', 'lot'])
                );
                $afterDiagnosis = $this->conceptReplayService->diagnoseInicialSplit($contract->fresh());
                $diff = $this->diffSnapshots($lotNumber, $before, $after);
                $diff['diagnosis'] = $diagnosis;
                $diff['after_diagnosis'] = $afterDiagnosis;
                $diff['folded'] = $folded;
                $diff['replay'] = ! $skipReplay;
                $diff['group'] = $this->inicialLotGroup($lotNumber);
                $diff['overage_destino'] = $this->inicialOverageDestino($diagnosis, $after);
                $report['lot_diffs'][] = $diff;
                foreach ($diff['status_flips'] as $flip) {
                    $report['collateral'][] = $flip;
                }
            }
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $apply();
                $report['portfolio_after'] = $this->sanMiguelPortfolioTotals();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($apply);
            $report['portfolio_after'] = $this->sanMiguelPortfolioTotals();
        }

        $path = app()->environment('testing')
            ? storage_path('app/testing-sm-wave-inicial-dry-run.json')
            : storage_path('app/sm-wave-inicial-dry-run.json');
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->printInicialWaveReport($report);
        $this->info("JSON: {$path}");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function selectedInicialLots(): array
    {
        $requested = array_map('strval', (array) $this->option('lot'));
        if ($requested === []) {
            return self::WAVE_INICIAL_LOTS;
        }

        return array_values(array_intersect(self::WAVE_INICIAL_LOTS, $requested));
    }

    private function inicialLotGroup(string $lotNumber): string
    {
        if (in_array($lotNumber, self::WAVE_INICIAL_NO_REPLAY, true)) {
            return 'G2 (sin replay)';
        }
        if (in_array($lotNumber, self::WAVE_B_LOTS, true)) {
            return 'G3 (replay también aplica extras B+C no persistidos)';
        }
        if (in_array($lotNumber, self::WAVE_C_LOTS, true)) {
            return 'G4 (replay también aplica extras B+C no persistidos)';
        }
        if (in_array($lotNumber, self::WAVE_AD_LOTS, true)) {
            return 'A+D ya persistido';
        }

        return 'sin grupo especial';
    }

    /**
     * @param  array<string, mixed>  $diagnosis
     * @param  array<string, mixed>  $after
     */
    private function inicialOverageDestino(array $diagnosis, array $after): string
    {
        $splits = $diagnosis['splits'] ?? [];
        if ($splits === []) {
            return 'sin leftover partido';
        }
        $leftover = $splits[0]['leftover'] ?? '0.00';
        $mora = (bool) ($splits[0]['mora_at_payment'] ?? false);
        $dust = (bool) ($splits[0]['dust'] ?? false);
        if ($mora) {
            return 'mora en #1 (vencida a la fecha del recibo)';
        }
        if ($dust) {
            return 'contract_residual_balances (polvo < $5.000)';
        }
        if (bccomp((string) $leftover, '0.00', 2) > 0) {
            return 'sobre-pactada en #0 (recibo entero en la inicial)';
        }

        return 'sin overage';
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printInicialWaveReport(array $report): void
    {
        $before = $report['portfolio_before'];
        $after = $report['portfolio_after'];
        $this->line('');
        $this->info('=== Portafolio SM ===');
        $this->line("recaudo before={$before['collected']} after={$after['collected']} txs before={$before['transactions']} after={$after['transactions']}");

        foreach ($report['lot_diffs'] as $diff) {
            $diag = $diff['diagnosis'] ?? [];
            $afterDiag = $diff['after_diagnosis'] ?? [];
            $this->line('');
            $this->info("=== Lote {$diff['lot']} · {$diff['group']} ===");
            $this->line("replay=".($diff['replay'] ? 'sí' : 'no (G2)')." destino={$diff['overage_destino']}");
            $this->line("pactada=".($diag['pactada'] ?? '-'));
            foreach ($diag['splits'] ?? [] as $split) {
                $this->line(sprintf(
                    '  HV rec %s %s = DP %s + leftover %s (fecha %s, mora=%s, polvo=%s)',
                    $split['receipt'],
                    $split['hv_amount'],
                    $split['dp_part'],
                    $split['leftover'],
                    $split['date'],
                    $split['mora_at_payment'] ? 'sí' : 'no',
                    $split['dust'] ? 'sí' : 'no',
                ));
                foreach ($split['allocations'] as $alloc) {
                    $this->line(sprintf(
                        '    leftover hoy → #%s amt=%s i=%s p=%s',
                        $alloc['n'] ?? '?',
                        $alloc['amount'],
                        $alloc['interest'],
                        $alloc['principal'],
                    ));
                }
            }
            $i0b = $diag['inicial'] ?? [];
            $i0a = $afterDiag['inicial'] ?? [];
            $i1b = $diag['cuota_1'] ?? [];
            $i1a = $afterDiag['cuota_1'] ?? [];
            $this->line(sprintf(
                '  #0 pp %s→%s debt %s→%s st %s→%s',
                $i0b['principal_paid'] ?? '-',
                $i0a['principal_paid'] ?? '-',
                $i0b['quota_debt'] ?? '-',
                $i0a['quota_debt'] ?? '-',
                $i0b['status'] ?? '-',
                $i0a['status'] ?? '-',
            ));
            $this->line(sprintf(
                '  #1 extra %s→%s ip %s→%s pp %s→%s debt %s→%s st %s→%s',
                $i1b['extra_payment'] ?? '-',
                $i1a['extra_payment'] ?? '-',
                $i1b['interest_paid'] ?? '-',
                $i1a['interest_paid'] ?? '-',
                $i1b['principal_paid'] ?? '-',
                $i1a['principal_paid'] ?? '-',
                $i1b['quota_debt'] ?? '-',
                $i1a['quota_debt'] ?? '-',
                $i1b['status'] ?? '-',
                $i1a['status'] ?? '-',
            ));
            $this->line("collected {$diff['collected']['before']} → {$diff['collected']['after']} txs {$diff['tx_count']['before']} → {$diff['tx_count']['after']}");
            $lsB = $diff['life_sheet']['before'];
            $lsA = $diff['life_sheet']['after'];
            $this->line("unimputed {$lsB['unimputed']} → {$lsA['unimputed']} residual {$diff['residual_pending']['before']} → {$diff['residual_pending']['after']}");
            foreach ($diff['changed_rows'] as $n => $pair) {
                if (! in_array((string) $n, ['0', '1'], true)) {
                    continue;
                }
                $b = $pair['before'];
                $a = $pair['after'];
                $this->line(sprintf(
                    '  #%s %s→%s ip %s→%s pp %s→%s extra %s→%s rem %s→%s debt %s→%s',
                    $n,
                    $b['status'] ?? '-',
                    $a['status'] ?? '-',
                    $b['interest_paid'] ?? '-',
                    $a['interest_paid'] ?? '-',
                    $b['principal_paid'] ?? '-',
                    $a['principal_paid'] ?? '-',
                    $b['extra_payment'] ?? '-',
                    $a['extra_payment'] ?? '-',
                    $b['remaining_balance'] ?? '-',
                    $a['remaining_balance'] ?? '-',
                    $b['quota_debt'] ?? '-',
                    $a['quota_debt'] ?? '-',
                ));
            }
        }

        $this->line('');
        $this->info('=== Efectos colaterales de estado ===');
        if ($report['collateral'] === []) {
            $this->line('Ninguno.');

            return;
        }
        foreach ($report['collateral'] as $flip) {
            $mark = $flip['kind'] === 'paid_to_open' ? '⚠ paid→abierta' : $flip['kind'];
            $this->line("Lote {$flip['lot']} #{$flip['installment']} {$flip['from']} → {$flip['to']} ({$mark})");
        }
    }

    /**
     * @return list<string>
     */
    private function selectedConceptWaveLots(string $wave): array
    {
        $pool = $wave === 'bc' ? self::WAVE_BC_LOTS : self::WAVE_AD_LOTS;
        $requested = array_map('strval', (array) $this->option('lot'));
        if ($requested === []) {
            return $pool;
        }

        return array_values(array_intersect($pool, $requested));
    }

    /**
     * @return array{collected: string, transactions: int, contracts: int}
     */
    private function sanMiguelPortfolioTotals(): array
    {
        $contracts = Contract::query()
            ->where('contract_number', 'like', 'SM-LOTE-%')
            ->whereNull('deleted_at')
            ->with('transactions')
            ->get();

        $collected = '0.00';
        $txCount = 0;
        foreach ($contracts as $contract) {
            foreach ($contract->transactions as $tx) {
                $collected = $this->money(bcadd($collected, $this->money($tx->amount), 2));
                $txCount++;
            }
        }

        return [
            'collected' => $collected,
            'transactions' => $txCount,
            'contracts' => $contracts->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailedSnapshot(Contract $contract): array
    {
        $contract->loadMissing(['installments', 'transactions']);
        $summary = $this->lifeSheetService->build($contract)['summary'];
        $rows = [];
        foreach ($contract->amortizationInstallments()->orderBy('installment_number')->get() as $row) {
            $n = (int) $row->installment_number;
            $rows[$n] = [
                'status' => $row->status instanceof AmortizationStatus
                    ? $row->status->value
                    : (string) $row->status,
                'interest_paid' => $this->money($row->interest_paid),
                'principal_paid' => $this->money($row->principal_paid),
                'extra_payment' => $this->money($row->extra_payment),
                'remaining_balance' => $this->money($row->remaining_balance),
                'quota_debt' => $this->money($row->quota_debt),
                'interest_value' => $this->money($row->interest_value),
                'principal_value' => $this->money($row->principal_value),
            ];
        }

        $residual = $this->money(ContractResidualBalance::query()
            ->where('contract_id', $contract->id)
            ->where('status', 'pendiente')
            ->sum('amount'));

        return [
            'contract_id' => $contract->id,
            'status' => $contract->status instanceof ContractStatus
                ? $contract->status->value
                : (string) $contract->status,
            'down_pending' => DownPaymentLedger::pending($contract),
            'collected' => $this->money($contract->transactions->sum(fn (Transaction $tx) => (float) $tx->amount)),
            'tx_count' => $contract->transactions->count(),
            'life_sheet' => [
                'unimputed' => $summary['unimputed'],
                'principal_paid' => $summary['principal_paid'],
                'interest_paid' => $summary['interest_paid'],
            ],
            'residual_pending' => $residual,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    private function diffSnapshots(string $lotNumber, array $before, array $after): array
    {
        $changed = [];
        $flips = [];
        $numbers = array_unique([...array_keys($before['rows']), ...array_keys($after['rows'])]);
        sort($numbers, SORT_NUMERIC);

        foreach ($numbers as $n) {
            $b = $before['rows'][$n] ?? null;
            $a = $after['rows'][$n] ?? null;
            if ($b === $a) {
                continue;
            }
            $changed[(string) $n] = ['before' => $b, 'after' => $a];
            $bStatus = $b['status'] ?? null;
            $aStatus = $a['status'] ?? null;
            if ($bStatus !== $aStatus) {
                $flips[] = [
                    'lot' => $lotNumber,
                    'installment' => $n,
                    'from' => $bStatus,
                    'to' => $aStatus,
                    'kind' => $this->statusFlipKind($bStatus, $aStatus),
                ];
            }
        }

        return [
            'lot' => $lotNumber,
            'contract_status' => ['before' => $before['status'], 'after' => $after['status']],
            'down_pending' => ['before' => $before['down_pending'], 'after' => $after['down_pending']],
            'collected' => ['before' => $before['collected'], 'after' => $after['collected']],
            'tx_count' => ['before' => $before['tx_count'], 'after' => $after['tx_count']],
            'life_sheet' => ['before' => $before['life_sheet'], 'after' => $after['life_sheet']],
            'residual_pending' => ['before' => $before['residual_pending'], 'after' => $after['residual_pending']],
            'changed_rows' => $changed,
            'status_flips' => $flips,
        ];
    }

    private function statusFlipKind(?string $from, ?string $to): string
    {
        if ($from === 'paid' && in_array($to, ['overdue', 'partial', 'pending'], true)) {
            return 'paid_to_open';
        }
        if (in_array($from, ['overdue', 'partial', 'pending'], true) && $to === 'paid') {
            return 'open_to_paid';
        }

        return 'other';
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConceptWaveReport(array $report): void
    {
        $before = $report['portfolio_before'];
        $after = $report['portfolio_after'];
        $this->line('');
        $this->info('=== Portafolio SM ===');
        $this->line("recaudo before={$before['collected']} after={$after['collected']} txs before={$before['transactions']} after={$after['transactions']}");

        foreach ($report['lot_diffs'] as $diff) {
            $this->line('');
            $this->info("=== Lote {$diff['lot']} ===");
            $this->line("status {$diff['contract_status']['before']} → {$diff['contract_status']['after']}");
            $this->line("inicial pending {$diff['down_pending']['before']} → {$diff['down_pending']['after']}");
            $this->line("collected {$diff['collected']['before']} → {$diff['collected']['after']} txs {$diff['tx_count']['before']} → {$diff['tx_count']['after']}");
            $lsB = $diff['life_sheet']['before'];
            $lsA = $diff['life_sheet']['after'];
            $this->line("unimputed {$lsB['unimputed']} → {$lsA['unimputed']} pp {$lsB['principal_paid']} → {$lsA['principal_paid']} ip {$lsB['interest_paid']} → {$lsA['interest_paid']}");
            $this->line("residual {$diff['residual_pending']['before']} → {$diff['residual_pending']['after']}");

            foreach ($diff['changed_rows'] as $n => $pair) {
                $b = $pair['before'];
                $a = $pair['after'];
                $this->line(sprintf(
                    '  #%s %s→%s ip %s→%s pp %s→%s extra %s→%s rem %s→%s debt %s→%s',
                    $n,
                    $b['status'] ?? '-',
                    $a['status'] ?? '-',
                    $b['interest_paid'] ?? '-',
                    $a['interest_paid'] ?? '-',
                    $b['principal_paid'] ?? '-',
                    $a['principal_paid'] ?? '-',
                    $b['extra_payment'] ?? '-',
                    $a['extra_payment'] ?? '-',
                    $b['remaining_balance'] ?? '-',
                    $a['remaining_balance'] ?? '-',
                    $b['quota_debt'] ?? '-',
                    $a['quota_debt'] ?? '-',
                ));
            }
        }

        $this->line('');
        $this->info('=== Efectos colaterales de estado ===');
        if ($report['collateral'] === []) {
            $this->line('Ninguno.');

            return;
        }
        foreach ($report['collateral'] as $flip) {
            $mark = $flip['kind'] === 'paid_to_open' ? '⚠ paid→abierta' : $flip['kind'];
            $this->line("Lote {$flip['lot']} #{$flip['installment']} {$flip['from']} → {$flip['to']} ({$mark})");
        }
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

        $this->calculationService->recalculateFutureKeepingQuota(
            $contract,
            (int) $installment->installment_number,
        );
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
