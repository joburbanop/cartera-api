<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardMetricsService
{
    public function carteraEnMora(): array
    {
        $installments = $this->cuotasVencidasQuery()
            ->get(['quota_debt', 'installment_value', 'interest_paid', 'principal_paid']);

        $total = '0.00';

        foreach ($installments as $installment) {
            $total = bcadd($total, $this->deudaCuota($installment), 2);
        }

        return [
            'total_vencido' => number_format((float) $total, 2, '.', ''),
            'cantidad_cuotas_vencidas' => $installments->count(),
        ];
    }

    public function recaudoReciente(): array
    {
        $start = Carbon::today()->startOfMonth()->toDateString();
        $endExclusive = Carbon::today()->addDay()->toDateString();

        $row = Transaction::query()
            ->where('transaction_date', '>=', $start)
            ->where('transaction_date', '<', $endExclusive)
            ->toBase()
            ->selectRaw('COUNT(*) as cantidad_pagos, COALESCE(SUM(amount), 0) as total_recaudado')
            ->first();

        if (is_array($row)) {
            $row = (object) $row;
        }

        return [
            'total_recaudado' => number_format((float) ($row?->total_recaudado ?? 0), 2, '.', ''),
            'cantidad_pagos' => (int) ($row?->cantidad_pagos ?? 0),
        ];
    }

    public function proximosVencimientos(int $dias = 7): array
    {
        $start = Carbon::today()->toDateString();
        $endExclusive = Carbon::today()->addDays($dias + 1)->toDateString();
        $debtSql = $this->cuotaDebtSql();

        $row = AmortizationInstallment::query()
            ->where('installment_number', '>', 0)
            ->where('due_date', '>=', $start)
            ->where('due_date', '<', $endExclusive)
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->toBase()
            ->selectRaw("COUNT(*) as cantidad_cuotas, COALESCE(SUM({$debtSql}), 0) as total_por_vencer")
            ->first();

        if (is_array($row)) {
            $row = (object) $row;
        }

        return [
            'total_por_vencer' => number_format((float) ($row?->total_por_vencer ?? 0), 2, '.', ''),
            'cantidad_cuotas' => (int) ($row?->cantidad_cuotas ?? 0),
        ];
    }

    public function actividadReciente(int $limite = 10): array
    {
        $pagos = Transaction::query()
            ->with(['contract.customer'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit($limite)
            ->get();

        $contratos = Contract::query()
            ->with('customer')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->limit($limite)
            ->get();

        $items = [];

        foreach ($pagos as $pago) {
            $tipo = $pago->transaction_type instanceof TransactionType
                ? $pago->transaction_type->value
                : (string) $pago->transaction_type;

            $items[] = [
                'tipo' => 'pago',
                'fecha' => optional($pago->transaction_date)?->toDateString(),
                'monto' => $this->money($pago->amount),
                'referencia' => $tipo,
                'cliente' => $pago->contract?->customer?->name,
                'contrato' => $pago->contract?->contract_number,
            ];
        }

        foreach ($contratos as $contrato) {
            $fecha = optional($contrato->start_date)?->toDateString()
                ?? optional($contrato->created_at)?->toDateString();

            $items[] = [
                'tipo' => 'contrato',
                'fecha' => $fecha,
                'monto' => $this->money($contrato->sale_price),
                'referencia' => (string) $contrato->contract_number,
                'cliente' => $contrato->customer?->name,
                'contrato' => $contrato->contract_number,
            ];
        }

        usort($items, function (array $a, array $b): int {
            return strcmp((string) ($b['fecha'] ?? ''), (string) ($a['fecha'] ?? ''));
        });

        return array_values(array_slice($items, 0, $limite));
    }

    public function clientesTotales(): array
    {
        return [
            'total_clientes' => Customer::query()->count(),
        ];
    }

    /**
     * @return array{total_proyectos_activos: int}
     */
    public function proyectosActivos(): array
    {
        $total = Project::query()
            ->where(function (Builder $query): void {
                $query->whereIn('status', ['active', 'activo'])
                    ->orWhereNull('status');
            })
            ->count();

        return [
            'total_proyectos_activos' => $total,
        ];
    }

    /**
     * @return list<array{mes: string, total: string}>
     */
    public function recaudoMensual(): array
    {
        $start = Carbon::today()->startOfMonth()->subMonths(11);
        $end = Carbon::today()->endOfMonth();

        $totals = [];

        for ($offset = 11; $offset >= 0; $offset--) {
            $totals[Carbon::today()->startOfMonth()->subMonths($offset)->format('Y-m')] = '0.00';
        }

        $monthSql = $this->yearMonthSql('transaction_date');

        $rows = Transaction::query()
            ->where('transaction_date', '>=', $start->toDateString())
            ->where('transaction_date', '<', $end->copy()->addDay()->toDateString())
            ->toBase()
            ->selectRaw("{$monthSql} as mes, COALESCE(SUM(amount), 0) as total")
            ->groupByRaw($monthSql)
            ->get();

        foreach ($rows as $row) {
            $mes = (string) $row->mes;

            if (! array_key_exists($mes, $totals)) {
                continue;
            }

            $totals[$mes] = number_format((float) $row->total, 2, '.', '');
        }

        $series = [];

        foreach ($totals as $mes => $total) {
            $series[] = [
                'mes' => $mes,
                'total' => $total,
            ];
        }

        return $series;
    }

    /**
     * @return array{vencidas: int, al_dia: int}
     */
    public function carteraVencidaResumen(): array
    {
        $vencidas = $this->cuotasVencidasQuery()->count();

        $alDia = AmortizationInstallment::query()
            ->where('installment_number', '>', 0)
            ->where(function (Builder $query): void {
                $query->whereDate('due_date', '>', Carbon::today())
                    ->orWhere('status', AmortizationStatus::PAID->value);
            })
            ->count();

        return [
            'vencidas' => $vencidas,
            'al_dia' => $alDia,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function contratosPorEstado(): array
    {
        $counts = Contract::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $result = [];

        foreach (ContractStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    public function lotesPorEstado(): array
    {
        $counts = Lot::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $result = [];

        foreach (LotStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    private function cuotasVencidasQuery(): Builder
    {
        return AmortizationInstallment::query()
            ->where('installment_number', '>', 0)
            ->where('due_date', '<', Carbon::today()->addDay()->toDateString())
            ->where('status', '!=', AmortizationStatus::PAID->value);
    }

    private function yearMonthSql(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    private function cuotaDebtSql(): string
    {
        $remainder = 'installment_value - interest_paid - principal_paid';

        if (DB::connection()->getDriverName() === 'sqlite') {
            return "CASE WHEN quota_debt > 0 THEN quota_debt ELSE MAX(0, {$remainder}) END";
        }

        return "CASE WHEN quota_debt > 0 THEN quota_debt ELSE GREATEST({$remainder}, 0) END";
    }

    private function deudaCuota(AmortizationInstallment $installment): string
    {
        $quotaDebt = $this->money($installment->quota_debt);

        if (bccomp($quotaDebt, '0.00', 2) === 1) {
            return $quotaDebt;
        }

        $remainder = bcsub(
            $this->money($installment->installment_value),
            bcadd(
                $this->money($installment->interest_paid),
                $this->money($installment->principal_paid),
                2
            ),
            2
        );

        return bccomp($remainder, '0.00', 2) === 1 ? $remainder : '0.00';
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
