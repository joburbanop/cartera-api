<?php

namespace App\Support\Financial;

use App\Enums\AmortizationStatusEnum;

/**
 * Adaptador de compatibilidad para estados legacy.
 * 
 * Permite migrar gradualmente los estados del sistema antiguo (AmortizationStatus)
 * al nuevo sistema unificado (AmortizationStatusEnum) sin romper funcionalidad existente.
 * 
 * @deprecated Usar directamente AmortizationStatusEnum en nuevos desarrollos.
 *             Esta clase será eliminada cuando no queden contratos usando el modelo legacy.
 */
final class LegacyState
{
    /**
     * Convierte un estado legacy al nuevo enum unificado.
     * 
     * @param string|null $legacyState Estado del sistema antiguo
     * @return string Valor del nuevo enum AmortizationStatusEnum
     */
    public static function resolveToNewEnum(?string $legacyState): string
    {
        if ($legacyState === null) {
            return AmortizationStatusEnum::PENDING->value;
        }

        $state = strtolower(trim($legacyState));

        return match ($state) {
            'sin_pagar', 'pending', 'unpaid', 'vencida', 'overdue' => AmortizationStatusEnum::PENDING->value,
            'parcial', 'partial' => AmortizationStatusEnum::PARTIAL->value,
            'pagada', 'paid' => AmortizationStatusEnum::PAID->value,
            default => AmortizationStatusEnum::PENDING->value,
        };
    }

    /**
     * Verifica si un estado legacy es equivalente a un estado del nuevo enum.
     * 
     * @param string $legacyState Estado del sistema antiguo
     * @param AmortizationStatusEnum $newEnum Estado del nuevo sistema
     */
    public static function isEquivalent(string $legacyState, AmortizationStatusEnum $newEnum): bool
    {
        $converted = self::resolveToNewEnum($legacyState);
        return $converted === $newEnum->value;
    }

    /**
     * Obtiene todos los estados legacy que mapean a un estado específico del nuevo enum.
     * 
     * @return string[] Lista de estados legacy equivalentes
     */
    public static function getLegacyEquivalents(AmortizationStatusEnum $newEnum): array
    {
        return match ($newEnum) {
            AmortizationStatusEnum::PENDING => ['sin_pagar', 'pending', 'unpaid', 'vencida', 'overdue'],
            AmortizationStatusEnum::PARTIAL => ['parcial', 'partial'],
            AmortizationStatusEnum::PAID => ['pagada', 'paid'],
        };
    }
}
