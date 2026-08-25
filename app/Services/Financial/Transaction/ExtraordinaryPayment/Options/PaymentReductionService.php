<?php

namespace App\Services\Financial\Transaction\ExtraordinaryPayment\Options;

use App\Enums\AmortizationStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentReductionService extends AbstractExtraordinaryPaymentService
{
    public function apply(
        Contract $contract,
        AmortizationInstallment $installment,
        string $surplusAmount
    ): AmortizationInstallment {
        if ($this->alreadyApplied($installment, $surplusAmount)) {
            return $installment->fresh();
        }

        $installment = $this->processBasePayment(
            $contract,
            $installment,
            $surplusAmount
        );

        $this->recalculateFuture(
            $contract,
            $installment
        );

        return $installment->fresh();
    }

    private function recalculateFuture(
        Contract $contract,
        AmortizationInstallment $paidInstallment
    ): void {
        DB::transaction(function () use ($contract, $paidInstallment) {

            $currentNumber = (int) $paidInstallment->installment_number;

            $balance = round(
                (float) (
                    $paidInstallment->remaining_balance
                    ?? $paidInstallment->projected_balance
                    ?? 0
                ),
                2
            );

            $rate = ((float) $contract->interest_rate) / 100;

            /*
             * Todas las cuotas posteriores a la cuota pagada
             * se mantienen. Solamente cambiamos sus valores.
             */
            $futureInstallments = $contract
                ->amortizationInstallments()
                ->where('installment_number', '>', $currentNumber)
                ->orderBy('installment_number')
                ->get();

            $remainingInstallments = $futureInstallments->count();

            if ($balance <= 0 || $remainingInstallments === 0) {
                return;
            }

            /*
             * Fórmula de amortización francesa:
             *
             * cuota = P * [r(1+r)^n] / [(1+r)^n - 1]
             */
            if ($rate > 0) {
                $factor = pow(1 + $rate, $remainingInstallments);

                $newInstallmentValue = round(
                    $balance
                    * (
                        ($rate * $factor)
                        / ($factor - 1)
                    ),
                    2
                );
            } else {
                $newInstallmentValue = round(
                    $balance / $remainingInstallments,
                    2
                );
            }

            foreach ($futureInstallments as $index => $futureInstallment) {

                $interest = round(
                    $balance * $rate,
                    2
                );

                /*
                 * La última cuota se ajusta para cerrar
                 * exactamente el saldo pendiente.
                 */
                if ($index === $remainingInstallments - 1) {
                    $installmentValue = round(
                        $balance + $interest,
                        2
                    );

                    $principal = round(
                        $balance,
                        2
                    );

                    $newBalance = 0.00;
                } else {
                    $installmentValue = $newInstallmentValue;

                    $principal = round(
                        max(
                            0,
                            $installmentValue - $interest
                        ),
                        2
                    );

                    $newBalance = round(
                        max(
                            0,
                            $balance - $principal
                        ),
                        2
                    );
                }

                $futureInstallment->update([
                    'installment_value' => $installmentValue,
                    'interest_value' => $interest,
                    'principal_value' => $principal,
                    'quota_debt' => $installmentValue,
                    'remaining_balance' => $newBalance,
                    'projected_balance' => $newBalance,
                    'extra_payment' => '0.00',
                    
                ]);

                $balance = $newBalance;

                if ($balance <= 0) {
                    break;
                }
            }
        });
    }
}