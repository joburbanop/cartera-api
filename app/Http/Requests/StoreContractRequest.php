<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->boolean('is_special_lot')) {
            return;
        }

        $startDate = $this->input('start_date');
        $salePrice = $this->input('sale_price');

        $this->merge([
            'is_custom_plan' => false,
            'down_payment_pactada' => $salePrice,
            'term_months' => $this->input('term_months', 0) ?? 0,
            'interest_rate' => $this->input('interest_rate', 0) ?? 0,
            'preventa_installments_count' => $this->input('preventa_installments_count', 0) ?? 0,
            'initial_payment_date' => $this->input('initial_payment_date') ?: $startDate,
            'first_installment_date' => $this->input('first_installment_date') ?: $startDate,
            'regular_payment_start_date' => $this->input('regular_payment_start_date')
                ?: ($this->input('first_installment_date') ?: $startDate),
        ]);
    }

    public static function calculateExpectedFutureValue(float $salePrice, float $downPayment, float $interestRate, int $termMonths): float
    {
        $principal = max(0.0, $salePrice - $downPayment);

        if ($principal <= 0 || $termMonths <= 0) {
            return 0.0;
        }

        $i = ($interestRate / 100);

        if ($i === 0.0) {
            return round($principal, 2);
        }

        $pmt = $principal * (($i * pow(1 + $i, $termMonths)) / (pow(1 + $i, $termMonths) - 1));

        return round($pmt * $termMonths, 2);
    }

    public static function calculateCustomPlanVariance(float $expectedFutureValue, float $totalCustom): float
    {
        return abs($totalCustom - $expectedFutureValue);
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $customerId = (int) $this->input('customer_id');
                $coTitularIds = array_map('intval', (array) $this->input('co_titular_ids', []));
                if ($customerId > 0 && in_array($customerId, $coTitularIds, true)) {
                    $validator->errors()->add('co_titular_ids', 'Un mismo cliente no puede aparecer más de una vez entre los titulares.');
                }

                if ($this->boolean('is_special_lot') || ! $this->boolean('is_custom_plan')) {
                    return;
                }

                $salePrice = (float) ($this->input('sale_price') ?? 0);
                $downPayment = (float) ($this->input('down_payment_pactada') ?? 0);
                $interestRate = (float) ($this->input('interest_rate') ?? 0);
                $termMonths = (int) ($this->input('term_months') ?? 0);
                $customPromises = $this->input('promises', []);

                $totalCustom = 0.0;
                foreach ($customPromises as $promise) {
                    $totalCustom += (float) ($promise['expected_amount'] ?? 0);
                }

                $expectedFutureValue = self::calculateExpectedFutureValue($salePrice, $downPayment, $interestRate, $termMonths);
                $variance = self::calculateCustomPlanVariance($expectedFutureValue, $totalCustom);

                if ($expectedFutureValue > 0 && $variance > 1000) {
                    $validator->errors()->add('promises', 'La suma de cuotas personalizadas debe cuadrar con el valor futuro de la deuda (PMT × plazo), con un margen de +/- $1,000.');
                }
            },
        ];
    }

    public function rules(): array
    {
        $isSpecialLot = $this->boolean('is_special_lot');

        return [
            'contract_number' => 'required|string|max:100|unique:contracts,contract_number',
            'customer_id' => 'nullable|integer',
            'customer_name' => 'nullable|string|max:150',
            'customer_document' => 'nullable|string|max:50',
            'customer_phone' => 'nullable|string|max:20',
            'customer_email' => 'nullable|email|max:150',
            'lot_id' => [
                'required',
                'exists:lots,id',
                Rule::unique('contracts', 'lot_id')
                    ->where(fn ($query) => $query
                        ->whereNull('deleted_at')
                        ->whereNotIn('status', ['rescindido'])),
            ],
            'seller_name' => 'nullable|string|max:150',
            'sale_price' => 'required|numeric|min:0',
            'down_payment_pactada' => $isSpecialLot ? 'nullable|numeric|min:0' : 'required|numeric|min:0',
            'term_months' => $isSpecialLot ? 'nullable|integer|min:0' : 'required|integer|min:1',
            'interest_rate' => 'nullable|numeric|min:0',
            'start_date' => 'required|date',
            'initial_payment_date' => $isSpecialLot
                ? ['nullable', 'date']
                : ['required', 'date', 'after_or_equal:start_date'],
            'first_installment_date' => $isSpecialLot
                ? ['nullable', 'date']
                : ['required', 'date', 'after_or_equal:start_date'],
            'regular_payment_start_date' => ['nullable', 'date', 'after_or_equal:first_installment_date'],
            'preventa_installments_count' => $isSpecialLot ? ['nullable', 'integer', 'min:0'] : ['required', 'integer', 'min:0'],
            'is_custom_plan' => 'boolean',
            'is_special_lot' => 'boolean',
            'promises' => 'nullable|array',
            'promises.*.expected_date' => 'required_with:promises|date',
            'promises.*.expected_amount' => 'required_with:promises|numeric|min:1',
            'promises.*.description' => 'required_with:promises|string',
            'co_titular_ids' => 'nullable|array',
            'co_titular_ids.*' => 'integer|distinct|exists:customers,id',
        ];
    }
}
