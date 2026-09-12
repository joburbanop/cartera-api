<?php

namespace App\Console\Commands;

use App\Services\Residual\ResidualBackfillScanner;
use App\Services\Residual\ResidualBalanceService;
use Illuminate\Console\Command;

/**
 * Camino B: backfill de residuales SM. Default: dry-run (CSV + MD, sin INSERT).
 * --persist solo inserta filas con evidencia. No toca cuotas ni transacciones.
 */
class BackfillSanMiguelResidualBalancesCommand extends Command
{
    protected $signature = 'residuals:backfill-san-miguel {--persist : Inserta las filas con evidencia. Sin este flag no escribe en DB.}';

    protected $description = 'Reconstruye residuales menores de San Miguel desde allocations/ledger. Dry-run por defecto.';

    public function handle(ResidualBackfillScanner $scanner): int
    {
        $persist = (bool) $this->option('persist');
        $rows = $scanner->scanSanMiguel();

        $csvPath = storage_path('app/reporte-backfill-residuales-sm.csv');
        $insertCsvPath = storage_path('app/reporte-backfill-residuales-sm-insert.csv');
        $mdPath = storage_path('app/reporte-backfill-residuales-sm.md');

        $this->writeCsv($csvPath, $rows);
        $inserts = array_values(array_filter(
            $rows,
            fn (array $row) => $row['decision'] === ResidualBackfillScanner::DECISION_INSERT
        ));
        $this->writeCsv($insertCsvPath, $inserts);
        $this->writeMarkdown($mdPath, $rows, $inserts, $persist);

        $summary = $this->summarize($rows);
        $this->info($persist
            ? 'Persistencia de residuales SM.'
            : 'Dry-run de residuales SM: no se escribió en contract_residual_balances.');
        $this->line("Cuotas pagadas evaluadas: {$summary['evaluated']}");
        $this->line("Insertables: {$summary['insert']} (suma {$summary['insert_sum']})");
        $this->line("Omitidas sin residual: {$summary['sin_residual']}");
        $this->line("Omitidas no reconstruibles: {$summary['no_reconstruible']}");
        $this->line("Ya existían: {$summary['ya_existe']}");
        $this->line("CSV audit: {$csvPath}");
        $this->line("CSV inserts: {$insertCsvPath}");
        $this->line("MD: {$mdPath}");

        if (! $persist) {
            return self::SUCCESS;
        }

        $created = $scanner->persistInserts($inserts);
        $this->info("Insertadas: {$created}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("No se pudo escribir {$path}");
        }

        fputcsv($handle, [
            'decision',
            'omit_reason',
            'evidence_method',
            'contract_number',
            'contract_id',
            'installment_number',
            'installment_id',
            'amount',
            'cash_applied',
            'book_alloc',
            'book_paid',
            'extra_payment',
            'allocation_count',
            'installment_value',
            'quota_debt',
            'notes',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['decision'],
                $row['omit_reason'],
                $row['evidence_method'],
                $row['contract_number'],
                $row['contract_id'],
                $row['installment_number'],
                $row['installment_id'],
                $row['amount'],
                $row['cash_applied'],
                $row['book_alloc'],
                $row['book_paid'],
                $row['extra_payment'],
                $row['allocation_count'],
                $row['installment_value'],
                $row['quota_debt'],
                $row['notes'],
            ]);
        }

        fclose($handle);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $inserts
     */
    private function writeMarkdown(string $path, array $rows, array $inserts, bool $persist): void
    {
        $summary = $this->summarize($rows);
        $byContract = [];
        foreach ($inserts as $row) {
            $number = (string) $row['contract_number'];
            if (! isset($byContract[$number])) {
                $byContract[$number] = ['count' => 0, 'sum' => '0.00'];
            }
            $byContract[$number]['count']++;
            $byContract[$number]['sum'] = bcadd($byContract[$number]['sum'], (string) $row['amount'], 2);
        }

        $lines = [];
        $lines[] = '# Backfill residuales SM (Camino B)';
        $lines[] = '';
        $lines[] = $persist
            ? 'Modo: **persist**. Solo INSERT en `contract_residual_balances`.'
            : 'Modo: **dry-run**. No se persistió nada.';
        $lines[] = '';
        $lines[] = 'Regla: no reconstruible → no insertar. No se tocan cuotas ni transacciones.';
        $lines[] = '';
        $lines[] = '## Totales';
        $lines[] = '';
        $lines[] = '| Métrica | Valor |';
        $lines[] = '|---|---|';
        $lines[] = "| Cuotas pagadas evaluadas | {$summary['evaluated']} |";
        $lines[] = "| Insertables | {$summary['insert']} |";
        $lines[] = "| Suma insertable | {$summary['insert_sum']} |";
        $lines[] = "| Omitidas sin residual | {$summary['sin_residual']} |";
        $lines[] = "| Omitidas no reconstruibles | {$summary['no_reconstruible']} |";
        $lines[] = "| Ya existían | {$summary['ya_existe']} |";
        $lines[] = '';
        $lines[] = '## Evidencia';
        $lines[] = '';
        $lines[] = '- `allocation_book_minus_cash`: cuota regular. `SUM(principal+interest) − SUM(amount)` de `transaction_allocations` target=installment, y ese libro coincide con `principal_paid+interest_paid`.';
        $lines[] = '- `down_payment_ledger`: inicial. `down_payment_pactada − collected`.';
        $lines[] = '- `allocation_exacto` / `allocation_cash_cubre_o_excede`: no hay faltante.';
        $lines[] = '- `sin_allocations` / `book_inconsistente` / `gap_excede_closer` / `cuota_no_cerrada`: no reconstruible.';
        $lines[] = '';
        $lines[] = '## Insertables por contrato';
        $lines[] = '';
        $lines[] = '| Contrato | Filas | Suma | ¿Cobro (≥ '.$this->collectibleThreshold().')? |';
        $lines[] = '|---|---:|---:|---|';
        ksort($byContract);
        foreach ($byContract as $number => $stats) {
            $collectible = bccomp($stats['sum'], $this->collectibleThreshold(), 2) >= 0 ? 'sí' : 'no';
            $lines[] = "| {$number} | {$stats['count']} | {$stats['sum']} | {$collectible} |";
        }
        if ($byContract === []) {
            $lines[] = '| — | 0 | 0.00 | — |';
        }
        $lines[] = '';
        $lines[] = '## Filas insertables';
        $lines[] = '';
        $lines[] = '| Contrato | # | Monto | Evidencia | Cash | Libro alloc |';
        $lines[] = '|---|---:|---:|---|---:|---:|';
        foreach ($inserts as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s |',
                $row['contract_number'],
                $row['installment_number'],
                $row['amount'],
                $row['evidence_method'],
                $row['cash_applied'],
                $row['book_alloc'],
            );
        }
        if ($inserts === []) {
            $lines[] = '| — | — | — | — | — | — |';
        }

        $noRecon = array_values(array_filter(
            $rows,
            fn (array $row) => ($row['omit_reason'] ?? '') === ResidualBackfillScanner::OMIT_NO_RECONSTRUIBLE
        ));
        $lines[] = '';
        $lines[] = '## No reconstruibles ('.$this->countOf($noRecon).')';
        $lines[] = '';
        if ($noRecon === []) {
            $lines[] = 'Ninguna.';
        } else {
            $lines[] = '| Contrato | # | Evidencia | Notas |';
            $lines[] = '|---|---:|---|---|';
            foreach ($noRecon as $row) {
                $lines[] = sprintf(
                    '| %s | %s | %s | %s |',
                    $row['contract_number'],
                    $row['installment_number'],
                    $row['evidence_method'],
                    str_replace('|', '/', (string) $row['notes']),
                );
            }
        }
        $lines[] = '';

        file_put_contents($path, implode("\n", $lines));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{evaluated: int, insert: int, insert_sum: string, sin_residual: int, no_reconstruible: int, ya_existe: int}
     */
    private function summarize(array $rows): array
    {
        $insertSum = '0.00';
        $insert = 0;
        $sinResidual = 0;
        $noRecon = 0;
        $yaExiste = 0;

        foreach ($rows as $row) {
            if ($row['decision'] === ResidualBackfillScanner::DECISION_INSERT) {
                $insert++;
                $insertSum = bcadd($insertSum, (string) $row['amount'], 2);

                continue;
            }

            match ($row['omit_reason'] ?? '') {
                ResidualBackfillScanner::OMIT_SIN_RESIDUAL => $sinResidual++,
                ResidualBackfillScanner::OMIT_NO_RECONSTRUIBLE => $noRecon++,
                ResidualBackfillScanner::OMIT_YA_EXISTE => $yaExiste++,
                default => null,
            };
        }

        return [
            'evaluated' => count($rows),
            'insert' => $insert,
            'insert_sum' => $insertSum,
            'sin_residual' => $sinResidual,
            'no_reconstruible' => $noRecon,
            'ya_existe' => $yaExiste,
        ];
    }

    private function collectibleThreshold(): string
    {
        return ResidualBalanceService::COLLECTIBLE_THRESHOLD;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function countOf(array $rows): int
    {
        return count($rows);
    }
}
