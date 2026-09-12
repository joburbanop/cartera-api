<?php

namespace App\Services\Financial\Transaction\DownPayment;

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\Lot;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\Collection\TransactionAllocationRecorder;
use App\Services\Financial\Amortization\AmortizationService;
use App\Services\Residual\ResidualBalanceService;
use App\Support\ContractCollectionGuard;
use App\Support\ContractFinancialLock;
use App\Support\DownPaymentLedger;
use App\Support\FinancialRules;
use App\Support\ReceiptNumber;
use App\Support\SafeUploadedFileName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DownPaymentService
{
    public function __construct(
        private readonly TransactionAllocationRecorder $allocationRecorder,
        private readonly ResidualBalanceService $residualBalanceService,
    ) {}

    public function registerDownPayment(CreateTransactionDTO $dto): Transaction
    {
        if ($dto->transactionType !== TransactionType::DOWN_PAYMENT) {
            throw ValidationException::withMessages([
                'transaction_type' => 'Esta ruta solo permite registrar abonos de cuota inicial.',
            ]);
        }

        return DB::transaction(function () use ($dto) {
            $contract = ContractFinancialLock::acquire($dto->contractId);
            ContractCollectionGuard::assertAcceptsPayments($contract);
            ReceiptNumber::assertUnusedOnContract($contract->id, $dto->receiptNumber);
            $pendingBalance = DownPaymentLedger::pending($contract);

            if ($this->residualIsWithinCompletionTolerance($pendingBalance)) {
                throw ValidationException::withMessages([
                    'amount' => 'La cuota inicial ya se encuentra completamente pagada.',
                ]);
            }

            if (bccomp($dto->amount, $pendingBalance, 2) === 1) {
                throw ValidationException::withMessages([
                    'amount' => 'El monto supera el saldo pendiente de la cuota inicial.',
                ]);
            }

            $receiptNumber = ReceiptNumber::normalize($dto->receiptNumber);
            $transaction = Transaction::create([
                'contract_id' => $contract->id,
                'transaction_type' => $dto->transactionType,
                'amount' => $dto->amount,
                'transaction_date' => $dto->transactionDate,
                'payment_method' => $dto->paymentMethod,
                'bank_account_id' => $dto->bankAccountId,
                'notes' => ReceiptNumber::mergeIntoNotes($dto->notes, $receiptNumber),
                'receipt_number' => $receiptNumber,
            ]);

            if ($dto->receipt) {
                $path = $dto->receipt->store('receipts', 'local');

                Receipt::create([
                    'transaction_id' => $transaction->id,
                    'file_path' => $path,
                    'file_name' => SafeUploadedFileName::forReceipt($dto->receipt),
                    'file_type' => $dto->receipt->getClientMimeType(),
                ]);
            }

            $initial = $contract->amortizationInstallments()
                ->where('installment_number', 0)
                ->first();
            $this->allocationRecorder->recordDownPayment(
                $transaction,
                $initial,
                (string) $dto->amount,
                (string) $dto->amount,
            );

            $this->updateInitialInstallment($contract, $dto);
            $this->activateContractWhenDownPaymentIsComplete($contract);

            return $transaction;
        });
    }

    /**
     * Recibo histórico de inicial: una sola transacción por el monto completo
     * del HV. Si el recibo supera el pendiente, #0 se cierra con lo pactado y
     * el caller decide el sobrante (mora → residual → sobre-pactada).
     *
     * @return array{transaction: Transaction, applied: string, overage: string}
     */
    public function registerInicialReceipt(CreateTransactionDTO $dto): array
    {
        if ($dto->transactionType !== TransactionType::DOWN_PAYMENT) {
            throw ValidationException::withMessages([
                'transaction_type' => 'Esta ruta solo permite registrar abonos de cuota inicial.',
            ]);
        }

        return DB::transaction(function () use ($dto) {
            $contract = ContractFinancialLock::acquire($dto->contractId);
            ContractCollectionGuard::assertAcceptsPayments($contract);
            ReceiptNumber::assertUnusedOnContract($contract->id, $dto->receiptNumber);
            $pendingBalance = DownPaymentLedger::pending($contract);
            $full = $this->normalizeMoney((string) $dto->amount);

            if ($this->residualIsWithinCompletionTolerance($pendingBalance)
                && bccomp($pendingBalance, '0.00', 2) <= 0
            ) {
                throw ValidationException::withMessages([
                    'amount' => 'La cuota inicial ya se encuentra completamente pagada.',
                ]);
            }

            $applied = bccomp($full, $pendingBalance, 2) === 1 ? $pendingBalance : $full;
            $overage = $this->normalizeMoney(bcsub($full, $applied, 2));
            $receiptNumber = ReceiptNumber::normalize($dto->receiptNumber);

            $transaction = Transaction::create([
                'contract_id' => $contract->id,
                'transaction_type' => $dto->transactionType,
                'amount' => $full,
                'transaction_date' => $dto->transactionDate,
                'payment_method' => $dto->paymentMethod,
                'bank_account_id' => $dto->bankAccountId,
                'notes' => ReceiptNumber::mergeIntoNotes($dto->notes, $receiptNumber),
                'receipt_number' => $receiptNumber,
            ]);

            $initial = $contract->amortizationInstallments()
                ->where('installment_number', 0)
                ->first();
            $this->allocationRecorder->recordDownPayment(
                $transaction,
                $initial,
                $applied,
                $applied,
            );

            $this->updateInitialInstallment($contract, new CreateTransactionDTO(
                contractId: $dto->contractId,
                amount: $applied,
                transactionDate: $dto->transactionDate,
                paymentMethod: $dto->paymentMethod,
                transactionType: $dto->transactionType,
                installmentNumbers: [],
                notes: $dto->notes,
            ));
            $this->activateContractWhenDownPaymentIsComplete($contract);

            return [
                'transaction' => $transaction,
                'applied' => $applied,
                'overage' => $overage,
            ];
        });
    }

    public function applyExistingDownPaymentToSchedule(Contract $contract, CreateTransactionDTO $dto): void
    {
        $this->updateInitialInstallment($contract, $dto);
        $this->activateContractWhenDownPaymentIsComplete($contract);
    }

    private function updateInitialInstallment(Contract $contract, CreateTransactionDTO $dto): void
    {
        $installments = $contract->amortizationInstallments()->orderBy('installment_number', 'asc')->get();

        if ($installments->isEmpty()) {
            $installments = app(AmortizationService::class)->generateInitialProjection($contract);
        }

        $initialInstallment = $installments->first(fn ($installment) => (int) $installment->installment_number === 0);

        if (! $initialInstallment) {
            return;
        }

        $updatedDebt = max(
            '0.00',
            bcsub(
                (string) ($initialInstallment->quota_debt ?? $contract->down_payment_pactada),
                (string) $dto->amount,
                2
            )
        );

        $isComplete = $this->residualIsWithinCompletionTolerance($updatedDebt);
        $principalValue = $this->normalizeMoney(
            (string) ($initialInstallment->principal_value ?? $contract->down_payment_pactada)
        );
        $interestValue = $this->normalizeMoney((string) ($initialInstallment->interest_value ?? '0.00'));
        $accumulatedPrincipal = $this->normalizeMoney(bcadd(
            (string) ($initialInstallment->principal_paid ?? '0.00'),
            (string) $dto->amount,
            2
        ));
        if (bccomp($accumulatedPrincipal, $principalValue, 2) === 1) {
            $accumulatedPrincipal = $principalValue;
        }

        $initialInstallment->update([
            'quota_debt' => $isComplete ? '0.00' : $updatedDebt,
            'remaining_balance' => (string) (
                $initialInstallment->remaining_balance
                ?? ($contract->sale_price - $contract->down_payment_pactada)
            ),
            'payment_date' => $dto->transactionDate->toDateString(),
            'status' => $isComplete ? AmortizationStatus::PAID : AmortizationStatus::PARTIAL,
            'principal_paid' => $isComplete ? $principalValue : $accumulatedPrincipal,
            'interest_paid' => $isComplete ? $interestValue : $initialInstallment->interest_paid,
        ]);

        if ($isComplete) {
            $this->residualBalanceService->recordIfMinor(
                (int) $contract->id,
                (int) $initialInstallment->id,
                (string) $updatedDebt,
                true,
            );
        }
    }

    public function activateContractWhenDownPaymentIsComplete(Contract $contract): void
    {
        $residual = $this->downPaymentResidual($contract);

        if (! $this->residualIsWithinCompletionTolerance($residual)) {
            return;
        }

        $this->markInitialInstallmentPaid($contract);

        $contract->update([
            'status' => ContractStatus::ACTIVO,
        ]);

        Lot::findOrFail($contract->lot_id)->update([
            'status' => LotStatus::VENDIDO,
        ]);
    }

    private function markInitialInstallmentPaid(Contract $contract): void
    {
        $initialInstallment = $contract->amortizationInstallments()
            ->where('installment_number', 0)
            ->first();

        if (! $initialInstallment) {
            return;
        }

        $principalValue = $this->normalizeMoney(
            (string) ($initialInstallment->principal_value ?? $contract->down_payment_pactada)
        );
        $interestValue = $this->normalizeMoney((string) ($initialInstallment->interest_value ?? '0.00'));

        $initialInstallment->update([
            'status' => AmortizationStatus::PAID,
            'quota_debt' => '0.00',
            'principal_paid' => $principalValue,
            'interest_paid' => $interestValue,
        ]);
    }

    private function downPaymentResidual(Contract $contract): string
    {
        return DownPaymentLedger::pending($contract);
    }

    private function residualIsWithinCompletionTolerance(string $residual): bool
    {
        return FinancialRules::residualIsWithinCompletionTolerance($residual);
    }

    private function normalizeMoney(string $value): string
    {
        return bcadd($value, '0', 2);
    }
}
