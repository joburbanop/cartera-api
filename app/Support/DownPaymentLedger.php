<?php

namespace App\Support;

use App\Enums\AllocationTarget;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\TransactionAllocation;

/**
 * Cuánto se ha recaudado de la cuota inicial de un contrato.
 *
 * No basta con sumar las transacciones de tipo `down_payment`: un pago mixto
 * llega como una sola transacción por el total que vio el banco, y la parte
 * que corresponde a la inicial vive en su reparto. Si esa parte no se cuenta,
 * la inicial nunca se da por saldada y el contrato no se activa.
 */
class DownPaymentLedger
{
    public static function collected(Contract $contract): string
    {
        $allocated = TransactionAllocation::query()
            ->where('target', AllocationTarget::DOWN_PAYMENT->value)
            ->whereHas(
                'transaction',
                fn ($query) => $query->where('contract_id', $contract->id)
            )
            ->sum('amount');

        // Abonos viejos de inicial que aún no tienen allocation: el monto de
        // la transacción es la verdad. Si ya hay allocation, no se suma otra vez.
        $directWithoutAllocation = $contract->transactions()
            ->where('transaction_type', TransactionType::DOWN_PAYMENT)
            ->whereDoesntHave(
                'allocations',
                fn ($query) => $query->where('target', AllocationTarget::DOWN_PAYMENT->value)
            )
            ->sum('amount');

        return bcadd(self::money((string) $allocated), self::money((string) $directWithoutAllocation), 2);
    }

    /** Lo que falta para completar la inicial. Nunca negativo. */
    public static function pending(Contract $contract): string
    {
        $pending = bcsub(
            self::money((string) $contract->down_payment_pactada),
            self::collected($contract),
            2
        );

        return bccomp($pending, '0.00', 2) > 0 ? $pending : '0.00';
    }

    public static function isSettled(Contract $contract): bool
    {
        return FinancialRules::residualIsWithinCompletionTolerance(self::pending($contract));
    }

    private static function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
