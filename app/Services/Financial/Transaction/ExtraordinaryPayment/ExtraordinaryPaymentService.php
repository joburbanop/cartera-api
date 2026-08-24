<?php

namespace App\Services\Financial\Transaction\ExtraordinaryPayment;

use App\DTOs\CreateTransactionDTO;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Transaction;
use App\Services\Financial\Transaction\ExtraordinaryPayment\Options\PaymentAdvanceService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\Options\PaymentReductionService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\Options\TermReductionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExtraordinaryPaymentService
{
    public function __construct(
        private readonly TermReductionService $termReductionService,
        private readonly PaymentReductionService $paymentReductionService,
        private readonly PaymentAdvanceService $paymentAdvanceService,
    ) {}

    public function registerExtraordinaryPayment(CreateTransactionDTO $dto): Transaction
    {
        if ($dto->transactionType !== \App\Enums\TransactionType::EXTRAORDINARY_PAYMENT) {
            throw ValidationException::withMessages([
                'transaction_type' => 'Este flujo solo aplica a pagos extraordinarios.',
            ]);
        }

        if (empty($dto->installmentNumbers)) {
            throw ValidationException::withMessages([
                'installment_number' => 'Debe seleccionar la cuota que recibe el abono.',
            ]);
        }

        $contract = Contract::findOrFail($dto->contractId);
        $installment = $contract->amortizationInstallments()
            ->where('id', $dto->installmentNumbers[0])
            ->orWhere('installment_number', $dto->installmentNumbers[0])
            ->firstOrFail();

        $surplusAmount = (string) $dto->amount;
        $option = strtolower((string) ($dto->paymentOption ?? ''));

        return DB::transaction(function () use ($contract, $installment, $surplusAmount, $option, $dto) {
            $transaction = Transaction::create([
                'contract_id' => $contract->id,
                'transaction_type' => $dto->transactionType,
                'amount' => $dto->amount,
                'transaction_date' => $dto->transactionDate,
                'payment_method' => $dto->paymentMethod,
            ]);

            $this->handle($contract, $installment, $surplusAmount, $option);

            return $transaction;
        });
    }

    public function handle(Contract $contract, AmortizationInstallment $installment, string $surplusAmount, string $option): AmortizationInstallment
    {
        $normalized = strtolower($option);

        if ($normalized === 'reducir_plazo') {
            return $this->termReductionService->apply($contract, $installment, $surplusAmount);
        }

        if ($normalized === 'reducir_cuota') {
            return $this->paymentReductionService->apply($contract, $installment, $surplusAmount);
        }

        if ($normalized === 'adelantar_cuotas') {
            return $this->paymentAdvanceService->apply($contract, $installment, $surplusAmount);
        }

        return $this->termReductionService->apply($contract, $installment, $surplusAmount);
    }
}
