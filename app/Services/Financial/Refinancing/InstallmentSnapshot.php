<?php

namespace App\Services\Financial\Refinancing;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use Illuminate\Support\Collection;

/**
 * Copia textual de las cuotas que una refinanciación va a borrar o modificar.
 *
 * Se guarda dentro del mismo registro de bitácora de la refinanciación. No es
 * un versionado: el respaldo legal es el «Otrosí» firmado. Esta copia existe
 * para poder reconstruir el plan anterior si hubo un error de digitación.
 */
final class InstallmentSnapshot
{
    /**
     * @param  Collection<int, AmortizationInstallment>  $installments
     * @return list<array<string, string|int|null>>
     */
    public static function of(Collection $installments): array
    {
        return $installments
            ->sortBy(fn (AmortizationInstallment $row) => (int) $row->installment_number)
            ->map(fn (AmortizationInstallment $row) => [
                'id' => (int) $row->id,
                'installment_number' => (int) $row->installment_number,
                'due_date' => $row->due_date?->toDateString(),
                'payment_date' => $row->payment_date?->toDateTimeString(),
                'installment_value' => self::money($row->installment_value),
                'interest_value' => self::money($row->interest_value),
                'principal_value' => self::money($row->principal_value),
                'extra_payment' => self::money($row->extra_payment),
                'interest_paid' => self::money($row->interest_paid),
                'principal_paid' => self::money($row->principal_paid),
                'quota_debt' => self::money($row->quota_debt),
                'remaining_balance' => self::money($row->remaining_balance),
                'projected_balance' => self::money($row->projected_balance),
                'status' => $row->status instanceof AmortizationStatus
                    ? $row->status->value
                    : (string) $row->status,
            ])
            ->values()
            ->all();
    }

    private static function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
    }
}
