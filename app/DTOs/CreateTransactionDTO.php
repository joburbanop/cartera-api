<?php

namespace App\DTOs;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Http\Requests\StoreTransactionRequest;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;

class CreateTransactionDTO
{
    public function __construct(
        public readonly int $contractId,
        public readonly string $amount,
        public readonly Carbon $transactionDate,
        public readonly PaymentMethod $paymentMethod,
        public readonly TransactionType $transactionType,
        public readonly array $installmentNumbers,
        public readonly ?string $paymentOption = null,
        public readonly ?string $recalculationType = null,
        public readonly ?UploadedFile $receipt = null,
    ) {}

    public static function fromRequest(
        StoreTransactionRequest $request,
        int $contractId
    ): self {
        $selectedInstallments = $request->input('selected_installments', $request->input('installment_numbers', []));
        $transactionDateValue = $request->input('transaction_date', $request->input('payment_date', now()->toDateString()));
        $transactionTypeValue = $request->input('transaction_type', $request->input('transactionType', TransactionType::DOWN_PAYMENT->value));

        if ($transactionTypeValue === null || $transactionTypeValue === '') {
            $transactionTypeValue = $selectedInstallments ? TransactionType::REGULAR_PAYMENT->value : TransactionType::DOWN_PAYMENT->value;
        }

        $paymentOption = $request->validated('payment_option', $request->validated('surplus_action', null));
        $recalculationType = $request->validated('recalculation_type', $paymentOption);

        return new self(
            contractId: $contractId,
            amount: (string) $request->validated('amount'),
            transactionDate: Carbon::parse($transactionDateValue),
            paymentMethod: PaymentMethod::from(
                $request->validated('payment_method')
            ),
            transactionType: TransactionType::from($transactionTypeValue),
            installmentNumbers: array_values(array_map('intval', (array) $selectedInstallments)),
            paymentOption: $paymentOption,
            recalculationType: $recalculationType,
            receipt: $request->file('receipt'),
        );
    }
}