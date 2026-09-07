<?php

namespace App\Support;

/**
 * Tolerancias de dinero del motor de cartera.
 *
 * Son TRES conceptos distintos; no los trates como el mismo umbral:
 * - QUOTA_COMPLETION_RESIDUAL ($500): condonación de residual para dar por
 *   cerrada la cuota inicial o una cuota regular.
 * - ABSORBED_SURPLUS ($2): excedente que se absorbe sin generar abono extra
 *   ni error 422.
 * - IMPUTATION_DUST ($1): polvo de centavos al imputar interés→capital,
 *   para no dejar 0.01 fantasma en quota_debt.
 */
final class FinancialRules
{
    public const QUOTA_COMPLETION_RESIDUAL = '500.00';

    public const ABSORBED_SURPLUS = '2.00';

    public const IMPUTATION_DUST = '1.00';

    /**
     * Half-up a 2 decimales (bcadd/bcsub 0.005, scale 2).
     * Misma regla que el PMT del front (`roundHalfUp2`).
     */
    public static function roundHalfUp2(string $value): string
    {
        if (bccomp($value, '0', 12) >= 0) {
            return bcadd($value, '0.005', 2);
        }

        return bcsub($value, '0.005', 2);
    }

    public static function residualIsWithinCompletionTolerance(string $residual): bool
    {
        return bccomp($residual, self::QUOTA_COMPLETION_RESIDUAL, 2) < 0;
    }

    public static function leftoverExceedsAbsorbedSurplus(string $amount): bool
    {
        return bccomp($amount, self::ABSORBED_SURPLUS, 2) > 0;
    }

    public static function isImputationDust(string $amount): bool
    {
        return bccomp($amount, '0.00', 2) > 0
            && bccomp($amount, self::IMPUTATION_DUST, 2) < 0;
    }
}
