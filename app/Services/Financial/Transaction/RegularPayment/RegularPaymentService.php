<?php

namespace App\Services\Financial\Transaction\RegularPayment;

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\TransactionType;
use App\Models\AmortizationPlan;
use App\Models\Contract;
use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegularPaymentService

{
    public function calculatePaymentImpact(
        AmortizationPlan $plan,
        string $paymentAmount,
        ?Contract $contract = null
    ): array {
        $installmentValue = (string) $plan->installment_value;

        $interestValue = (string) (
            $plan->interest_value ?? '0.00'
        );

        $principalValue = (string) (
            $plan->principal_value
            ?? bcsub($installmentValue, $interestValue, 2)
        );

        $interestAlreadyPaid = (string) (
            $plan->interest_paid ?? '0.00'
        );

        $principalAlreadyPaid = (string) (
            $plan->principal_paid ?? '0.00'
        );

        $totalAlreadyPaid = bcadd(
            $interestAlreadyPaid,
            $principalAlreadyPaid,
            2
        );

        $currentQuotaDebt = (string) (
            $plan->quota_debt ?? '0.00'
        );

        $pendingDebt = bccomp($currentQuotaDebt, '0.00', 2) > 0
            ? $currentQuotaDebt
            : bcsub(
                $installmentValue,
                $totalAlreadyPaid,
                2
            );

        $pendingDebt = max('0.00', $pendingDebt);

        $remainingInterestToPay = bcsub(
            $interestValue,
            $interestAlreadyPaid,
            2
        );

        $remainingPrincipalToPay = bcsub(
            $principalValue,
            $principalAlreadyPaid,
            2
        );

        /*
         * 1. PAGO PARCIAL
         */
        if (bccomp($paymentAmount, $pendingDebt, 2) < 0) {

            $interestPaidNow = bccomp(
                $paymentAmount,
                $remainingInterestToPay,
                2
            ) <= 0
                ? $paymentAmount
                : $remainingInterestToPay;

            $paymentLeftForCapital = bcsub(
                $paymentAmount,
                $interestPaidNow,
                2
            );

            $principalPaidNow = bccomp(
                $paymentLeftForCapital,
                $remainingPrincipalToPay,
                2
            ) <= 0
                ? $paymentLeftForCapital
                : $remainingPrincipalToPay;

            $newInterestPaid = bcadd(
                $interestAlreadyPaid,
                $interestPaidNow,
                2
            );

            $newPrincipalPaid = bcadd(
                $principalAlreadyPaid,
                $principalPaidNow,
                2
            );

            $updatedQuotaDebt = max(
                '0.00',
                bcsub(
                    $pendingDebt,
                    $paymentAmount,
                    2
                )
            );

            $status = $contract
                && $contract->status === ContractStatus::PREVENTA_INACTIVA
                    ? AmortizationStatus::PARTIAL
                    : AmortizationStatus::OVERDUE;

            return [
                'status' => $status,
                'quota_debt' => $updatedQuotaDebt,
                'interest_paid' => $newInterestPaid,
                'principal_paid' => $newPrincipalPaid,
                'excedente' => '0.00',
            ];
        }

        /*
         * 2. PAGO EXACTO
         */
        if (bccomp($paymentAmount, $pendingDebt, 2) === 0) {

            return [
                'status' => AmortizationStatus::PAID,
                'quota_debt' => '0.00',
                'interest_paid' => $interestValue,
                'principal_paid' => $principalValue,
                'excedente' => '0.00',
            ];
        }

        /*
         * 3. PAGO SUPERIOR
         *
         * La cuota queda pagada y el excedente
         * pasa al flujo de pago extraordinario.
         */
        $surplus = bcsub(
            $paymentAmount,
            $pendingDebt,
            2
        );

        return [
            'status' => AmortizationStatus::PAID,
            'quota_debt' => '0.00',
            'interest_paid' => $interestValue,
            'principal_paid' => $principalValue,
            'excedente' => $surplus,
        ];
    }

    public function registerRegularPayment(CreateTransactionDTO $dto): Transaction
{
    if ($dto->transactionType !== TransactionType::REGULAR_PAYMENT) {
        throw ValidationException::withMessages([
            'transaction_type' => 'Esta ruta solo permite registrar pagos regulares.',
        ]);
    }

    return DB::transaction(function () use ($dto) {

        $contract = Contract::findOrFail($dto->contractId);

        $installmentNumber = $dto->installmentNumbers[0] ?? null;

        if ($installmentNumber === null) {
            throw ValidationException::withMessages([
                'installment_number' => 'Debe seleccionar una cuota.',
            ]);
        }

        $plan = AmortizationPlan::where('contract_id', $contract->id)
            ->where('installment_number', (int) $installmentNumber)
            ->first();

        if (! $plan) {
            throw ValidationException::withMessages([
                'installment_number' => 'La cuota seleccionada no existe.',
            ]);
        }

        $impact = $this->calculatePaymentImpact(
            $plan,
            (string) $dto->amount,
            $contract
        );

        $transaction = Transaction::create([
            'contract_id' => $contract->id,
            'transaction_type' => $dto->transactionType,
            'amount' => $dto->amount,
            'transaction_date' => $dto->transactionDate,
            'payment_method' => $dto->paymentMethod,
        ]);

        $path = $dto->receipt->store('receipts', 'local');

        Receipt::create([
            'transaction_id' => $transaction->id,
            'file_path' => $path,
            'file_name' => $dto->receipt->getClientOriginalName(),
            'file_type' => $dto->receipt->getClientMimeType(),
        ]);

        $plan->update([
            'status' => $impact['status'],
            'quota_debt' => $impact['quota_debt'],
            'interest_paid' => $impact['interest_paid'],
            'principal_paid' => $impact['principal_paid'],
            'payment_date' => $dto->transactionDate,
            'extra_payment' => $impact['excedente'],
        ]);

        return $transaction;
    });
}
}