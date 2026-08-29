<?php

namespace App\Services\Financial\Transaction\RegularPayment;

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\Financial\Amortization\AmortizationService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegularPaymentService

{
    private function resolvePartialStatus(AmortizationInstallment $plan, ?Contract $contract = null): AmortizationStatus
    {
        if ($contract && $contract->status === ContractStatus::PREVENTA_INACTIVA) {
            return AmortizationStatus::PARTIAL;
        }

        $dueDate = $plan->due_date ?? null;

        if (! $dueDate) {
            return AmortizationStatus::OVERDUE;
        }

        return Carbon::parse($dueDate)->startOfDay()->lt(now()->startOfDay())
            ? AmortizationStatus::OVERDUE
            : AmortizationStatus::PARTIAL;
    }

    private function normalizeSurplus(string $surplus): string
    {
        if (bccomp($surplus, '0.00', 2) <= 0) {
            return '0.00';
        }

        return bccomp($surplus, '2.00', 2) <= 0 ? '0.00' : $surplus;
    }

    public function calculatePaymentImpact(
        AmortizationInstallment $plan,
        string $paymentAmount,
        ?Contract $contract = null
    ): array {
        $installmentValue = (string) ($plan->installment_value ?? '0.00');

        $interestValue = (string) ($plan->interest_value ?? '0.00');

        $principalValue = (string) (
            $plan->principal_value
            ?? bcsub($installmentValue, $interestValue, 2)
        );

        $interestAlreadyPaid = (string) ($plan->interest_paid ?? '0.00');

        $principalAlreadyPaid = (string) ($plan->principal_paid ?? '0.00');

        $totalAlreadyPaid = bcadd(
            $interestAlreadyPaid,
            $principalAlreadyPaid,
            2
        );

        $currentQuotaDebt = (string) ($plan->quota_debt ?? '0.00');

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

            if (bccomp($updatedQuotaDebt, '500.00', 2) < 0) {
                return [
                    'status' => AmortizationStatus::PAID,
                    'quota_debt' => '0.00',
                    'interest_paid' => $interestValue,
                    'principal_paid' => $principalValue,
                    'excedente' => '0.00',
                ];
            }

            $status = $this->resolvePartialStatus($plan, $contract);

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
        $surplus = $this->normalizeSurplus(bcsub(
            $paymentAmount,
            $pendingDebt,
            2
        ));

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
        $planCollection = $contract->amortizationInstallments()->orderBy('installment_number', 'asc')->get();

        if ($planCollection->isEmpty()) {
            $planCollection = app(AmortizationService::class)->generateInitialProjection($contract);
        }

        $selectedInstallmentId = $dto->installmentNumbers[0] ?? null;

        if ($selectedInstallmentId === null) {
            throw ValidationException::withMessages([
                'installment_number' => 'Debe seleccionar una cuota.',
            ]);
        }

        $plan = $planCollection->first(fn ($installment) => (int) $installment->id === (int) $selectedInstallmentId)
            ?? $planCollection->first(fn ($installment) => (int) $installment->installment_number === (int) $selectedInstallmentId);

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

        if ($dto->receipt) {
            $path = $dto->receipt->store('receipts', 'local');

            Receipt::create([
                'transaction_id' => $transaction->id,
                'file_path' => $path,
                'file_name' => $dto->receipt->getClientOriginalName(),
                'file_type' => $dto->receipt->getClientMimeType(),
            ]);
        }

        $projectedBalance = (string) ($plan->projected_balance ?? $plan->remaining_balance ?? '0.00');
        $surplus = (string) ($impact['excedente'] ?? '0.00');

        \Log::info('RegularPaymentService::surplus-start', [
            'contract_id' => $contract->id,
            'installment_id' => $plan->id,
            'installment_number' => $plan->installment_number,
            'amount' => $dto->amount,
            'surplus' => $surplus,
            'projected_balance' => $projectedBalance,
            'plan_interest' => $plan->interest_value ?? null,
            'plan_installment_value' => $plan->installment_value ?? null,
        ]);

        if (bccomp($surplus, '0.00', 2) > 0) {
            $previousInstallment = $contract->amortizationInstallments()
                ->where('installment_number', (int) ($plan->installment_number ?? 0) - 1)
                ->first();

            \Log::info('RegularPaymentService::previous-installment-check', [
                'contract_id' => $contract->id,
                'current_installment_number' => $plan->installment_number,
                'previous_installment_found' => (bool) $previousInstallment,
                'previous_installment_id' => $previousInstallment?->id,
                'previous_installment_number' => $previousInstallment?->installment_number,
                'previous_remaining_balance' => $previousInstallment?->remaining_balance ?? null,
                'previous_projected_balance' => $previousInstallment?->projected_balance ?? null,
            ]);

            $startingBalance = $previousInstallment
                ? (float) ($previousInstallment->remaining_balance ?? $previousInstallment->projected_balance ?? 0)
                : (float) ($plan->projected_balance ?? $plan->remaining_balance ?? 0);

            if ($startingBalance <= 0) {
                $startingBalance = round((float) ($contract->sale_price ?? 0) - (float) ($contract->down_payment_pactada ?? 0), 2);
            }

            $interestValue = (float) ($plan->interest_value ?? 0);
            if ($interestValue <= 0) {
                $interestValue = round($startingBalance * ((float) ($contract->interest_rate ?? 0) / 100), 2);
            }

            $installmentValue = (string) ($plan->installment_value ?? '0.00');
            $regularPrincipal = round((float) $installmentValue - $interestValue, 2);
            $availableForExtraPayment = round(max(0.0, $startingBalance - $regularPrincipal), 2);
            $effectiveSurplus = round(min((float) $surplus, $availableForExtraPayment), 2);
            $totalPrincipalPaid = round($regularPrincipal + $effectiveSurplus, 2);
            $adjustedBalance = round(max(0.0, $startingBalance - $totalPrincipalPaid), 2);

            \Log::info('RegularPaymentService::surplus-calculation', [
                'contract_id' => $contract->id,
                'installment_number' => $plan->installment_number,
                'starting_balance' => $startingBalance,
                'interest_value' => $interestValue,
                'regular_principal' => $regularPrincipal,
                'surplus' => $surplus,
                'effective_surplus' => $effectiveSurplus,
                'total_principal_paid' => $totalPrincipalPaid,
                'adjusted_balance' => $adjustedBalance,
            ]);

            $plan->extra_payment = $effectiveSurplus;
            $plan->interest_value = $interestValue;
            $plan->principal_value = (string) $totalPrincipalPaid;
            $plan->status = AmortizationStatus::PAID->value;
            $plan->payment_date = $dto->transactionDate;
            $plan->remaining_balance = $adjustedBalance;
            $plan->projected_balance = $adjustedBalance;
            $plan->save();

            $plan->refresh();

            $recalculationType = strtolower((string) ($dto->recalculationType ?? $dto->paymentOption ?? 'reducir_plazo'));

            if ($recalculationType === 'reducir_plazo' || $recalculationType === 'reduce_term') {
                app(\App\Services\Financial\Transaction\ExtraordinaryPayment\Options\TermReductionService::class)
                    ->recalculateFuture($contract, $plan->fresh());
            } elseif ($recalculationType === 'reducir_cuota' || $recalculationType === 'reduce_quota') {
                app(\App\Services\Financial\Transaction\ExtraordinaryPayment\Options\PaymentReductionService::class)
                    ->recalculateFuture($contract, $plan->fresh());
            }

            $option = strtolower((string) ($dto->paymentOption ?? ''));
            if ($option !== '') {
                $extraService = app(ExtraordinaryPaymentService::class);
                $extraService->handle($contract, $plan->fresh(), (string) $effectiveSurplus, $option);
            }

            return $transaction;
        }

        // calculatePaymentImpact ya devuelve la imputación acumulada de la cuota,
        // así que se asigna directamente en lugar de sumarla sobre lo persistido.
        $interestPaid = (string) ($impact['interest_paid'] ?? '0.00');
        $principalPaid = (string) ($impact['principal_paid'] ?? '0.00');

        if (bccomp((string) $dto->amount, (string) ($plan->quota_debt ?? $plan->installment_value ?? '0.00'), 2) === 0) {
            $plan->update([
                'status' => AmortizationStatus::PAID->value,
                'quota_debt' => '0.00',
                'payment_date' => $dto->transactionDate,
                'interest_paid' => $interestPaid,
                'principal_paid' => $principalPaid,
                'remaining_balance' => $projectedBalance,
                'projected_balance' => $projectedBalance,
            ]);

            return $transaction;
        }

        if (bccomp((string) $dto->amount, (string) ($plan->quota_debt ?? $plan->installment_value ?? '0.00'), 2) < 0) {
            $plan->update([
                'status' => AmortizationStatus::PARTIAL->value,
                'quota_debt' => round((float) ($plan->installment_value ?? 0) - (float) $dto->amount, 2),
                'payment_date' => $dto->transactionDate,
                'interest_paid' => $interestPaid,
                'principal_paid' => $principalPaid,
                'remaining_balance' => $projectedBalance,
                'projected_balance' => $projectedBalance,
            ]);

            return $transaction;
        }

        $plan->update([
            'status' => $impact['status'],
            'quota_debt' => $impact['quota_debt'],
            'payment_date' => $dto->transactionDate,
            'extra_payment' => $surplus,
            'principal_value' => (string) ($plan->principal_value ?? bcsub((string) ($plan->installment_value ?? '0.00'), (string) ($plan->interest_value ?? '0.00'), 2)),
            'interest_paid' => $interestPaid,
            'principal_paid' => $principalPaid,
            'remaining_balance' => $projectedBalance,
            'projected_balance' => $projectedBalance,
        ]);

        return $transaction;
    });
}
}