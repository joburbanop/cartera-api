<?php

namespace App\DTOs;

use App\Http\Requests\StoreCascadePaymentRequest;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;

class CascadePaymentDTO
{
    public function __construct(
        public readonly int $contractId,
        public readonly string $amount,
        public readonly ?string $paymentOption,
        public readonly Carbon $transactionDate,
        public readonly array $selectedInstallments,
        public readonly ?UploadedFile $receipt = null,
    ) {}

    public static function fromRequest(StoreCascadePaymentRequest $request): self
    {
        $rawDate = $request->input('payment_date', $request->input('transaction_date'));
        $selectedInstallments = $request->input('selected_installments', $request->input('installment_numbers', []));

        return new self(
            contractId: (int) $request->validated('contract_id'),
            amount: (string) $request->validated('amount'),
            paymentOption: $request->input('payment_option'),
            transactionDate: self::parseInputDate($rawDate),
            selectedInstallments: array_values(array_map('intval', (array) $selectedInstallments)),
            receipt: $request->file('receipt'),
        );
    }

    private static function parseInputDate(mixed $rawDate): Carbon
    {
        if ($rawDate instanceof \DateTimeInterface) {
            return Carbon::instance($rawDate);
        }

        if (is_string($rawDate)) {
            $value = trim($rawDate);

            if ($value === '') {
                return Carbon::now()->startOfDay();
            }

            if (str_contains($value, '/')) {
                return Carbon::createFromFormat('d/m/Y', $value)->startOfDay();
            }

            return Carbon::parse($value)->startOfDay();
        }

        return Carbon::now()->startOfDay();
    }
}
