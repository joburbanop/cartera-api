<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\AmortizationStatus;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

class DashboardMetricsService
{
    public function carteraEnMora(): array
    {
        $installments = AmortizationInstallment::query()
            ->where('installment_number', '>', 0)
            ->whereDate('due_date', '<=', Carbon::today())
            ->where('status', '!=', AmortizationStatus::PAID->value)
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
        $transactions = Transaction::query()
            ->whereDate('transaction_date', '>=', Carbon::today()->startOfMonth())
            ->whereDate('transaction_date', '<=', Carbon::today())
            ->get(['amount']);

        $total = '0.00';

        foreach ($transactions as $transaction) {
            $total = bcadd($total, $this->money($transaction->amount), 2);
        }

        return [
            'total_recaudado' => number_format((float) $total, 2, '.', ''),
            'cantidad_pagos' => $transactions->count(),
        ];
    }

    public function proximosVencimientos(int $dias = 7): array
    {
        $installments = AmortizationInstallment::query()
            ->where('installment_number', '>', 0)
            ->whereDate('due_date', '>=', Carbon::today())
            ->whereDate('due_date', '<=', Carbon::today()->addDays($dias))
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->get(['quota_debt', 'installment_value', 'interest_paid', 'principal_paid']);

        $total = '0.00';

        foreach ($installments as $installment) {
            $total = bcadd($total, $this->deudaCuota($installment), 2);
        }

        return [
            'total_por_vencer' => number_format((float) $total, 2, '.', ''),
            'cantidad_cuotas' => $installments->count(),
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
