<?php

namespace App\Services\Financial\Transaction\ExtraordinaryPayment\Options;

use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use Illuminate\Support\Facades\DB;

class PaymentReductionService extends AbstractExtraordinaryPaymentService
{
    public function __construct(
        private readonly AmortizationCalculationService $amortizationCalculationService,
    ) {}

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

            $balance = (string) (
                $paidInstallment->remaining_balance
                ?? $paidInstallment->projected_balance
                ?? '0.00'
            );

            $futureInstallments = $contract
                ->amortizationInstallments()
                ->where('installment_number', '>', $currentNumber)
                ->orderBy('installment_number')
                ->get();

            $remainingInstallments = $futureInstallments->count();

            if (
                bccomp($balance, '0.00', 2) <= 0
                || $remainingInstallments === 0
            ) {
                return;
            }

            /*
             * Calculamos la nueva cuota fija utilizando
             * el servicio central de cálculos financieros.
             */
            $newInstallmentValue = $this->amortizationCalculationService
                ->calculateFixedQuota(
                    $balance,
                    (string) $contract->interest_rate,
                    $remainingInstallments
                );

            foreach ($futureInstallments as $index => $futureInstallment) {

                /*
                 * Interés de la cuota actual.
                 */
                $interest = $this->amortizationCalculationService
                    ->calculateInterest(
                        $balance,
                        (string) $contract->interest_rate
                    );

                /*
                 * La última cuota se ajusta para cerrar
                 * exactamente el saldo pendiente.
                 */
                if ($index === $remainingInstallments - 1) {

                    $installmentValue = bcadd(
                        $balance,
                        $interest,
                        2
                    );

                    $principal = $balance;

                    $newBalance = '0.00';

                } else {

                    $installmentValue = $newInstallmentValue;

                    /*
                     * Capital = cuota - interés.
                     */
                    $principal = $this->amortizationCalculationService
                        ->calculatePrincipal(
                            $installmentValue,
                            $interest
                        );

                    /*
                     * Nuevo saldo = saldo anterior - capital.
                     */
                    $newBalance = $this->amortizationCalculationService
                        ->calculateRemainingBalance(
                            $balance,
                            $principal
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

                if (bccomp($balance, '0.00', 2) <= 0) {
                    break;
                }
            }
        });
    }
}