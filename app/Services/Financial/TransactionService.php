<?php

namespace App\Services\Financial;

use App\DTOs\CreateTransactionDTO;
use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\Lot;
use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function registerDownPayment(CreateTransactionDTO $dto)
    {
        return DB::transaction(function () use ($dto) {

            $contract = Contract::findOrFail($dto->contractId);

            $totalAbonado = $contract->transactions()
                ->where('transaction_type', TransactionType::DOWN_PAYMENT)
                ->sum('amount');

           $saldoPendiente = bcsub((string) $contract->down_payment_pactada,(string) $totalAbonado,2);

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

            $transaction = Transaction::create([
                'contract_id' => $contract->id,
                'transaction_type' => TransactionType::DOWN_PAYMENT,
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

            if (bccomp($dto->amount, (string) $saldoPendiente, 2) === 0) {

                $contract->update([
                    'status' => ContractStatus::ACTIVO,
                ]);

                $lot = Lot::findOrFail($contract->lot_id);

                $lot->update([
                    'status' => LotStatus::VENDIDO,
                ]);
            }

            return $transaction;
        });
    }
}