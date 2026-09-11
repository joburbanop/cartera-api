<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Vencida = due_date < $asOf (día calendario). Sin $asOf, $asOf es hoy.
 * El día de vencimiento sigue siendo corriente: no es mora.
 *
 * Esta regla debe mantenerse sincronizada con
 * `src/app/core/models/amortization-status.ts` → `isVencida`.
 * Si cambias el criterio de mora aquí, cámbialo también allá.
 *
 * Diferencia conocida (no “arreglarla” de paso): due nulo o vacío
 * aquí es mora (`true`); en TypeScript `isVencida` devuelve `false`.
 */
final class DueDateRules
{
    public static function isOverdue(mixed $dueDate, mixed $asOf = null): bool
    {
        if ($dueDate === null || $dueDate === '') {
            return true;
        }

        return Carbon::parse($dueDate)->startOfDay()
            ->lt(Carbon::parse($asOf ?? now())->startOfDay());
    }

    public static function asOfDate(mixed $asOf = null): string
    {
        return Carbon::parse($asOf ?? now())->toDateString();
    }
}
