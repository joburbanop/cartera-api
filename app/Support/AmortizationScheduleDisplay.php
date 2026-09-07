<?php

namespace App\Support;

final class AmortizationScheduleDisplay
{
    public static function interestAmount(object $row): float
    {
        $paid = (float) ($row->interest_paid ?? 0);
        if ($paid > 0) {
            return $paid;
        }

        return (float) ($row->interest_value ?? 0);
    }

    public static function amortizationAmount(object $row): float
    {
        $paid = (float) ($row->principal_paid ?? 0);
        if ($paid > 0) {
            return $paid;
        }

        $number = (int) ($row->installment_number ?? -1);
        if ($number === 0) {
            $pactada = (float) ($row->principal_value ?? $row->installment_value ?? 0);
            if (isset($row->quota_debt) && $row->quota_debt !== null && $row->quota_debt !== '') {
                return max(0, $pactada - (float) $row->quota_debt);
            }
        }

        return (float) ($row->principal_value ?? 0);
    }
}
