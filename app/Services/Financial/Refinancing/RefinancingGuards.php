<?php

namespace App\Services\Financial\Refinancing;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Support\FinancialRules;
use Illuminate\Validation\ValidationException;

final class RefinancingGuards
{
    /**
     * Una cuota con abono parcial no se puede borrar ni reescribir: lo ya
     * abonado se perdería y el cliente volvería a pagar capital que ya entregó.
     * Hay que liquidarla o cerrarla antes de refinanciar.
     */
    public static function assertAnchorIsNotPartiallyPaid(AmortizationInstallment $anchor): void
    {
        $status = $anchor->status instanceof AmortizationStatus
            ? $anchor->status->value
            : (string) $anchor->status;

        $interestPaid = bcadd((string) ($anchor->interest_paid ?? '0'), '0', 2);
        $principalPaid = bcadd((string) ($anchor->principal_paid ?? '0'), '0', 2);
        $extraPayment = bcadd((string) ($anchor->extra_payment ?? '0'), '0', 2);
        $paid = bcadd(bcadd($interestPaid, $principalPaid, 2), $extraPayment, 2);

        if ($status !== AmortizationStatus::PARTIAL->value && bccomp($paid, '0.00', 2) <= 0) {
            return;
        }

        throw ValidationException::withMessages([
            'anchor_installment' => sprintf(
                'La cuota %d, donde arranca la refinanciación, tiene un abono parcial de %s. '
                .'Debe liquidarla o cerrarla antes de refinanciar.',
                (int) $anchor->installment_number,
                number_format((float) $paid, 2, ',', '.'),
            ),
        ]);
    }

    /**
     * Refinanciar saldo consolida SUM(principal_paid) como nueva inicial.
     * Si la cuota 0 sigue abierta, esa consolidación mezcla conceptos.
     */
    public static function assertInitialInstallmentIsClosed(?AmortizationInstallment $initial): void
    {
        if (! $initial) {
            throw ValidationException::withMessages([
                'initial_installment' => 'No hay cuota inicial registrada; no se puede refinanciar el saldo.',
            ]);
        }

        $status = $initial->status instanceof AmortizationStatus
            ? $initial->status->value
            : (string) $initial->status;

        if ($status === AmortizationStatus::PAID->value) {
            return;
        }

        $debt = bcadd((string) ($initial->quota_debt ?? '0'), '0', 2);
        if (FinancialRules::residualIsWithinCompletionTolerance($debt)) {
            return;
        }

        throw ValidationException::withMessages([
            'initial_installment' => sprintf(
                'La cuota inicial aún tiene un saldo de %s. '
                .'Debe liquidarla o cerrarla antes de refinanciar el saldo.',
                number_format((float) $debt, 2, ',', '.'),
            ),
        ]);
    }
}
