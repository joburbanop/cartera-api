<?php

namespace App\Services\Residual;

use App\Enums\ResidualBalanceStatus;
use App\Models\ContractResidualBalance;

/**
 * Residuales menores que el closer de $500 condonó al cerrar la cuota.
 * Constantes propias: no comparte umbrales con FinancialRules ni con
 * DownPaymentService.
 */
class ResidualBalanceService
{
    /** Techo inclusive para clasificar un faltante como residual menor. */
    public const MINOR_RESIDUAL_CAP = '5000.00';

    /** Habilita cobrar el acumulado como ítem aparte. No oculta el SUM. */
    public const COLLECTIBLE_THRESHOLD = '500.00';

    /**
     * Persiste el monto condonado si es un residual menor y la cuota ya cerró.
     * Idempotente por cuota origen.
     */
    public function recordIfMinor(
        int $contractId,
        int $installmentId,
        string $leftover,
        bool $quotaClosed,
    ): ?ContractResidualBalance {
        $amount = $this->normalizeMoney($leftover);

        if (! $quotaClosed || ! $this->isMinorResidual($amount)) {
            return null;
        }

        return ContractResidualBalance::query()->firstOrCreate(
            ['amortization_installment_id' => $installmentId],
            [
                'contract_id' => $contractId,
                'amount' => $amount,
                'status' => ResidualBalanceStatus::PENDIENTE,
            ],
        );
    }

    public function pendingSum(int $contractId): string
    {
        $sum = ContractResidualBalance::query()
            ->where('contract_id', $contractId)
            ->where('status', ResidualBalanceStatus::PENDIENTE)
            ->sum('amount');

        return $this->normalizeMoney((string) $sum);
    }

    public function isCollectible(int $contractId, ?string $pendingSum = null): bool
    {
        $pending = $pendingSum ?? $this->pendingSum($contractId);

        return bccomp($pending, self::COLLECTIBLE_THRESHOLD, 2) >= 0;
    }

    /**
     * @return array{
     *     pending_sum: string,
     *     collectible: bool,
     *     collectible_threshold: string,
     *     minor_residual_cap: string
     * }
     */
    public function summary(int $contractId): array
    {
        $pendingSum = $this->pendingSum($contractId);

        return [
            'pending_sum' => $pendingSum,
            'collectible' => $this->isCollectible($contractId, $pendingSum),
            'collectible_threshold' => self::COLLECTIBLE_THRESHOLD,
            'minor_residual_cap' => self::MINOR_RESIDUAL_CAP,
        ];
    }

    public function isMinorResidual(string $amount): bool
    {
        $normalized = $this->normalizeMoney($amount);

        return bccomp($normalized, '0.00', 2) > 0
            && bccomp($normalized, self::MINOR_RESIDUAL_CAP, 2) <= 0;
    }

    private function normalizeMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
