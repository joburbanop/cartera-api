<?php

namespace App\Console\Commands;

use App\Services\Imports\SanMiguelImportService;
use Illuminate\Console\Command;

class ImportSanMiguelCommand extends Command
{
    protected $signature = 'import:san-miguel {archivo=app/imports/SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx} {--dry-run : Recorre el Excel sin escribir en la base de datos} {--solo-lote= : Importa solo la pestaña LOTE N} {--fresh : Borra contratos de San Miguel (incl. PRUEBA) antes de importar}';

    protected $description = 'Importa el histórico de lotes del proyecto San Miguel desde el Excel de hojas de vida';

    public function handle(SanMiguelImportService $service): int
    {
        $path = $this->resolvePath((string) $this->argument('archivo'));
        if (! is_file($path)) {
            $this->error("No se encontró el archivo: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $soloLote = $this->option('solo-lote');
        $soloLote = is_string($soloLote) && trim($soloLote) !== '' ? trim($soloLote) : null;
        $fresh = (bool) $this->option('fresh');
        if ($fresh && $dryRun) {
            $this->warn('--fresh se ignora en --dry-run (no se borra nada).');
            $fresh = false;
        }
        $this->info($dryRun ? 'Modo validación (--dry-run): no se escribirá nada en la base de datos.' : 'Importación real: cada lote en su propia transacción.');
        if ($fresh) {
            $this->warn('Fase 0 (--fresh): se borrarán contratos de San Miguel, incluido PRUEBA.');
        }
        $this->line('Archivo: '.$path);
        if ($soloLote !== null) {
            $this->line('Solo lote: '.$soloLote);
        }

        $report = $service->import($path, $dryRun, $soloLote, $fresh);
        if ($soloLote !== null && $report['sheets'] === 0) {
            $this->error("No hay pestaña LOTE {$soloLote} en el archivo.");

            return self::FAILURE;
        }

        $this->renderReport($report);

        if (! $dryRun && $report['failed'] !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $argument): string
    {
        if (is_file($argument)) {
            return $argument;
        }

        $fromBase = base_path($argument);
        if (is_file($fromBase)) {
            return $fromBase;
        }

        $fromStorage = storage_path('app/imports/'.basename($argument));
        if (is_file($fromStorage)) {
            return $fromStorage;
        }

        $legacy = base_path('app/imports/'.basename($argument));
        if (is_file($legacy)) {
            return $legacy;
        }

        return $argument;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $this->newLine();
        $this->info('========== REPORTE SAN MIGUEL ==========');
        $this->line('Proyecto: '.($report['project'] ?? '(no encontrado)'));
        if (! empty($report['project_missing'])) {
            $this->warn('El proyecto San Miguel no está en la base. En modo real se crearía "Proyecto San Miguel".');
        }
        $this->line('Pestañas LOTE: '.$report['sheets']);
        $this->line('Cuota variable: '.$report['variable']);
        $this->line('Lotes especiales: '.$report['especial']);
        $this->line('Pagos en el Excel: '.$report['payments']);
        $this->line('Clientes a crear: '.$report['customers_would_create']);
        $this->line('Clientes a reutilizar: '.$report['customers_would_reuse']);
        $this->line('Lotes sin observaciones de datos: '.$report['lots_ok']);
        $this->line('Lotes con observaciones/inconsistencias: '.$report['lots_with_issues']);

        $this->newLine();
        $this->info('--- Detalle por lote ---');
        foreach ($report['lot_details'] as $detail) {
            $balanceLabel = $detail['kind'] === 'especial'
                ? sprintf(
                    'saldo_excel=%s esperado=%s cuadra=%s',
                    $detail['last_excel_saldo'] ?? 'n/a',
                    $detail['expected_saldo'],
                    $detail['saldo_matches'] ? 'sí' : 'NO',
                )
                : sprintf(
                    'suma_pagos=%s pagos_vs_precio=%s',
                    $detail['sum_payments'],
                    $detail['saldo_matches'] ? 'ok' : 'EXCEDE',
                );

            $this->line(sprintf(
                '%s [%s] precio=%s inicial=%s tasa=%s plazo=%s pagos=%d %s',
                $detail['sheet'],
                $detail['kind'],
                $detail['sale_price'],
                $detail['down_payment'],
                $detail['interest_rate'],
                $detail['term_months'],
                $detail['payments'],
                $balanceLabel,
            ));
            foreach ($detail['clients'] as $client) {
                $this->line(sprintf(
                    '    titular %s | doc %s | %s',
                    $client['name'],
                    $client['document'] ?? '(sin doc)',
                    $client['action'] === 'create' ? 'CREAR' : 'REUTILIZAR',
                ));
            }
            foreach ($detail['issues'] as $issue) {
                $this->warn('    ! '.$issue);
            }
        }

        if ($report['inconsistencies'] !== []) {
            $this->newLine();
            $this->warn('--- Inconsistencias (lista plana) ---');
            foreach ($report['inconsistencies'] as $item) {
                $this->warn($item);
            }
        }

        if (! $report['dry_run']) {
            $this->newLine();
            $this->info('Lotes importados: '.$report['imported']);
            if ($report['failed'] === []) {
                $this->info('Ningún lote falló.');
            } else {
                $this->error('Lotes fallidos:');
                foreach ($report['failed'] as $failed) {
                    $this->error(sprintf('  %s: %s', $failed['sheet'], $failed['reason']));
                }
            }
        }

        if (! empty($report['fresh'])) {
            $this->newLine();
            $this->info('Fase 0 (--fresh): contratos borrados='.$report['fresh']['contracts'].' lotes a disponible='.$report['fresh']['lots_reset']);
        }

        $historical = $report['historical'] ?? null;
        if (is_array($historical)) {
            $this->newLine();
            if (! empty($historical['skipped'])) {
                $this->line('Fases 2-4: omitidas ('.($historical['reason'] ?? 'n/a').')');
            } else {
                $this->info('Fase 2 overlay: '.count($historical['overlay_lots'] ?? []).' lotes');
                $this->info('Fase 3 abonos huérfanos: '.count($historical['orphan_extras'] ?? []).' lotes');
                $this->info('Fase 4 vencimientos: '.count($historical['due_dates'] ?? []).' lotes');
            }
        }

        $this->info('========== FIN REPORTE ==========');
    }
}
