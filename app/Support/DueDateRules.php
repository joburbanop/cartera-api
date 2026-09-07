<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Vencida = due_date < hoy (día calendario).
 * El día de vencimiento sigue siendo corriente: no es mora.
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
