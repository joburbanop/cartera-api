<?php

namespace App\Services\Financial;

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Enums\TransactionType;
use App\Models\AmortizationPlan;
use App\Models\Contract;
use App\Models\Lot;
use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function __construct(
        private ?AmortizationRecalculatorService $amortizationRecalculatorService = null
    ) {
        $this->amortizationRecalculatorService ??= new AmortizationRecalculatorService();
    }

    public function calculatePaymentImpactForInstallment(AmortizationPlan $plan, string $paymentAmount, ?Contract $contract = null): array
    {
        $installmentValue = (string) $plan->installment_value;
        $interestValue = (string) ($plan->interest_value ?? '0.00');
        $principalValue = (string) ($plan->principal_value ?? bcsub($installmentValue, $interestValue, 2));
        $previousRemainingBalance = (string) ($plan->remaining_balance ?? $installmentValue);

        $rawInstallmentNumber = $plan->installment_number ?? null;
        $isInitialInstallment = $rawInstallmentNumber === 0
            || strtolower((string) $rawInstallmentNumber) === 'inicial'
            || strtolower((string) $rawInstallmentNumber) === 'incial';

        if ($isInitialInstallment) {
            $currentQuotaDebt = (string) ($plan->quota_debt ?? $installmentValue);
            $updatedQuotaDebt = max('0.00', bcsub($currentQuotaDebt, $paymentAmount, 2));
            $status = bccomp($updatedQuotaDebt, '0.00', 2) === 0
                ? AmortizationStatus::PAID
                : AmortizationStatus::PARTIAL;

            return [
                'status' => $status,
                'remaining_balance' => $previousRemainingBalance,
                'quota_debt' => $updatedQuotaDebt,
                'interest_paid' => '0.00',
                'principal_paid' => '0.00',
                'excedente' => bccomp($paymentAmount, $currentQuotaDebt, 2) > 0 ? bcsub($paymentAmount, $currentQuotaDebt, 2) : '0.00',
            ];
        }

        $interestAlreadyPaid = (string) ($plan->interest_paid ?? '0.00');
        $principalAlreadyPaid = (string) ($plan->principal_paid ?? '0.00');
        $totalAlreadyPaid = bcadd($interestAlreadyPaid, $principalAlreadyPaid, 2);
        $currentQuotaDebt = (string) ($plan->quota_debt ?? '0.00');
        $pendingDebt = bccomp($currentQuotaDebt, '0.00', 2) > 0
            ? $currentQuotaDebt
            : bcsub($installmentValue, $totalAlreadyPaid, 2);
        $pendingDebt = max('0.00', $pendingDebt);

        $remainingInterestToPay = bcsub($interestValue, $interestAlreadyPaid, 2);
        $remainingPrincipalToPay = bcsub($principalValue, $principalAlreadyPaid, 2);

        if (bccomp($paymentAmount, $pendingDebt, 2) < 0) {
            $interestPaidNow = bccomp($paymentAmount, $remainingInterestToPay, 2) <= 0
                ? $paymentAmount
                : $remainingInterestToPay;

            $paymentLeftForCapital = bcsub($paymentAmount, $interestPaidNow, 2);
            $principalPaidNow = bccomp($paymentLeftForCapital, $remainingPrincipalToPay, 2) <= 0
                ? $paymentLeftForCapital
                : $remainingPrincipalToPay;

            $newInterestPaid = bcadd($interestAlreadyPaid, $interestPaidNow, 2);
            $newPrincipalPaid = bcadd($principalAlreadyPaid, $principalPaidNow, 2);
            $updatedQuotaDebt = max('0.00', bcsub($pendingDebt, $paymentAmount, 2));
            $status = $contract && $contract->status === ContractStatus::PREVENTA_INACTIVA
                ? AmortizationStatus::PARTIAL
                : AmortizationStatus::OVERDUE;

            return [
                'status' => $status,
                'remaining_balance' => $previousRemainingBalance,
                'quota_debt' => $updatedQuotaDebt,
                'interest_paid' => $newInterestPaid,
                'principal_paid' => $newPrincipalPaid,
                'excedente' => '0.00',
            ];
        }

        if (bccomp($paymentAmount, $pendingDebt, 2) === 0) {
            return [
                'status' => AmortizationStatus::PAID,
                'remaining_balance' => $previousRemainingBalance,
                'quota_debt' => '0.00',
                'interest_paid' => $interestValue,
                'principal_paid' => $principalValue,
                'excedente' => '0.00',
            ];
        }

        $surplus = bcsub($paymentAmount, $pendingDebt, 2);

        return [
            'status' => AmortizationStatus::PAID,
            'remaining_balance' => max('0.00', bcsub($previousRemainingBalance, $surplus, 2)),
            'quota_debt' => '0.00',
            'interest_paid' => $interestValue,
            'principal_paid' => $principalValue,
            'excedente' => $surplus,
        ];
    }

    public function registerDownPayment(CreateTransactionDTO $dto)
    {
        return DB::transaction(function () use ($dto) {

            $contract = Contract::findOrFail($dto->contractId);
            $isDownPayment = $dto->transactionType === TransactionType::DOWN_PAYMENT;

            if ($isDownPayment) {
                $totalAbonado = $contract->transactions()
                    ->where('transaction_type', TransactionType::DOWN_PAYMENT)
                    ->sum('amount');

                $saldoPendiente = bcsub((string) $contract->down_payment_pactada, (string) $totalAbonado, 2);

                if ($saldoPendiente <= 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'La cuota inicial ya se encuentra completamente pagada.',
                    ]);
                }

                if (bccomp($dto->amount, (string) $saldoPendiente, 2) === 1) {
                    throw ValidationException::withMessages([
                        'amount' => 'El monto supera el saldo pendiente de la cuota inicial.',
                    ]);
                }
            }

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

            if ($isDownPayment) {
                $totalAbonadoActualizado = $contract->transactions()
                    ->where('transaction_type', TransactionType::DOWN_PAYMENT)
                    ->sum('amount');

                $cuotaInicialPlan = AmortizationPlan::where('contract_id', $contract->id)
                    ->where('installment_number', 0)
                    ->first();

                if ($cuotaInicialPlan) {
                    $nuevaDeudaInicial = max('0.00', bcsub((string) ($cuotaInicialPlan->quota_debt ?? $contract->down_payment_pactada), (string) $dto->amount, 2));
                    $cuotaInicialPlan->update([
                        'quota_debt' => $nuevaDeudaInicial,
                        'remaining_balance' => (string) ($cuotaInicialPlan->remaining_balance ?? ($contract->sale_price - $contract->down_payment_pactada)),
                        'status' => bccomp($nuevaDeudaInicial, '0.00', 2) === 0
                            ? AmortizationStatus::PAID
                            : AmortizationStatus::PARTIAL,
                    ]);

                    if ($totalAbonadoActualizado >= $contract->down_payment_pactada) {
                        $cuotaInicialPlan->update([
                            'status' => AmortizationStatus::PAID,
                        ]);
                    } elseif ($totalAbonadoActualizado > 0) {
                        $cuotaInicialPlan->update([
                            'status' => AmortizationStatus::PARTIAL,
                        ]);
                    }
                }

                if (bccomp((string) $totalAbonadoActualizado, (string) $contract->down_payment_pactada, 2) >= 0) {
                    $contract->update([
                        'status' => ContractStatus::ACTIVO,
                    ]);

                    $lot = Lot::findOrFail($contract->lot_id);

                    $lot->update([
                        'status' => LotStatus::VENDIDO,
                    ]);
                }
            }

            if ($dto->transactionType === TransactionType::REGULAR_PAYMENT) {
                $selectedNumbers = $dto->installmentNumbers;

                if (empty($selectedNumbers)) {
                    $selectedNumbers = AmortizationPlan::where('contract_id', $contract->id)
                        ->where('installment_number', '>', 0)
                        ->whereIn('status', [AmortizationStatus::UNPAID, AmortizationStatus::PARTIAL])
                        ->orderBy('installment_number')
                        ->pluck('installment_number')
                        ->toArray();
                }

                foreach ($selectedNumbers as $installmentNumber) {
                    $plan = AmortizationPlan::where('contract_id', $contract->id)
                        ->where('installment_number', (int) $installmentNumber)
                        ->first();

                    if (!$plan) {
                        continue;
                    }

                    $paymentAmount = (string) $dto->amount;
                    $impact = $this->calculatePaymentImpactForInstallment($plan, $paymentAmount, $contract);

                    $excedente = isset($impact['excedente']) ? (string) $impact['excedente'] : '0.00';
                    $isReducedTermOption = bccomp($excedente, '0.00', 2) > 0
                        && !empty($dto->paymentOption)
                        && strtolower((string) $dto->paymentOption) === 'reducir_plazo';

                    if ($isReducedTermOption) {
                        $baseRemainingBalance = (string) ($plan->remaining_balance ?? '0.00');

                        $this->amortizationRecalculatorService->applyExcess(
                            $contract,
                            $plan,
                            $excedente,
                            $dto->paymentOption,
                            $baseRemainingBalance,
                        );
                    } else {
                        $plan->update([
                            'status' => $impact['status'],
                            'remaining_balance' => $impact['remaining_balance'],
                            'interest_paid' => $impact['interest_paid'],
                            'principal_paid' => $impact['principal_paid'],
                            'quota_debt' => $impact['quota_debt'],
                            'extra_payment' => $excedente,
                        ]);
                    }

                    if ($impact['status'] === AmortizationStatus::OVERDUE) {
                        $contract->update([
                            'status' => ContractStatus::VENCIDO,
                        ]);
                    }
                }
            }

            return $transaction;
        });
    }
}