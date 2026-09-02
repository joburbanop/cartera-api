<?php

namespace App\Services\Financial\Transaction\DownPayment;

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Lot;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\Financial\Amortization\AmortizationService;
use App\Support\SafeUploadedFileName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DownPaymentService
{
    public function registerDownPayment(CreateTransactionDTO $dto): Transaction
    {
        if ($dto->transactionType !== TransactionType::DOWN_PAYMENT) {
            throw ValidationException::withMessages([
                'transaction_type' => 'Esta ruta solo permite registrar abonos de cuota inicial.',
            ]);
        }

        return DB::transaction(function () use ($dto) {
            $contract = Contract::findOrFail($dto->contractId);
            $totalPaid = $contract->transactions()
                ->where('transaction_type', TransactionType::DOWN_PAYMENT)
                ->sum('amount');
            $pendingBalance = bcsub(
                (string) $contract->down_payment_pactada,
                (string) $totalPaid,
                2
            );

            if (bccomp($pendingBalance, '0.00', 2) <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'La cuota inicial ya se encuentra completamente pagada.',
                ]);
            }

            if (bccomp($dto->amount, $pendingBalance, 2) === 1) {
                throw ValidationException::withMessages([
                    'amount' => 'El monto supera el saldo pendiente de la cuota inicial.',
                ]);
            }

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
                    'file_name' => SafeUploadedFileName::forReceipt($dto->receipt),
                    'file_type' => $dto->receipt->getClientMimeType(),
                ]);
            }

            $this->updateInitialInstallment($contract, $dto);
            $this->activateContractWhenDownPaymentIsComplete($contract);

            return $transaction;
        });
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

        $initialInstallment->update([
            'quota_debt' => $updatedDebt,
            'remaining_balance' => (string) (
                $initialInstallment->remaining_balance
                ?? ($contract->sale_price - $contract->down_payment_pactada)
            ),
            'payment_date' => $dto->transactionDate->toDateString(),
            'status' => bccomp($updatedDebt, '0.00', 2) === 0
                ? AmortizationStatus::PAID
                : AmortizationStatus::PARTIAL,
        ]);
    }

    private function activateContractWhenDownPaymentIsComplete(Contract $contract): void
    {
        $totalPaid = $contract->transactions()
            ->where('transaction_type', TransactionType::DOWN_PAYMENT)
            ->sum('amount');

        if (bccomp((string) $totalPaid, (string) $contract->down_payment_pactada, 2) < 0) {
            return;
        }

        $contract->update([
            'status' => ContractStatus::ACTIVO,
        ]);

        Lot::findOrFail($contract->lot_id)->update([
            'status' => LotStatus::VENDIDO,
        ]);
    }
}
