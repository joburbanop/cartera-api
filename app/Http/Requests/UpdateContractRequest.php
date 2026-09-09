<?php

namespace App\Http\Requests;

use App\Enums\ContractStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Enums\RoleName;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

class UpdateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $customerId = $this->input('customer_id');

        if ($customerId === '' || $customerId === '0' || $customerId === 0) {
            $this->merge([
                'customer_id' => null,
            ]);
        }

        if ($this->has('co_titular_ids')) {
            $this->merge([
                'co_titular_ids' => array_values(
                    array_unique(
                        array_map('intval', (array) $this->input('co_titular_ids', []))
                    )
                ),
            ]);
        }
    }

    public function rules(): array
    {
        $contract = $this->route('contract');

        $contractId = is_object($contract)
            ? $contract->id
            : $contract;

        return [
            /*
             * Datos administrativos
             */
            'contract_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('contracts', 'contract_number')
                    ->ignore($contractId),
            ],

           'customer_id' => [
                'required',
                'integer',
                'exists:customers,id',
            ],

            'customer_email' => [
                'nullable',
                'email',
                'max:150',
            ],

            'seller_name' => [
                'nullable',
                'string',
                'max:150',
            ],

            'co_titular_ids' => [
                'nullable',
                'array',
            ],

            'co_titular_ids.*' => [
                'integer',
                'distinct',
                'exists:customers,id',
            ],

            /*
             * Lote
             */
            'lot_id' => [
                'required',
                'integer',
                'exists:lots,id',
            ],

            /*
             * Condiciones financieras
             */
            'sale_price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'down_payment_pactada' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'term_months' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'interest_rate' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'start_date' => [
                'required',
                'date',
            ],

            'initial_payment_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],

            'first_installment_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],

            'regular_payment_start_date' => [
                'nullable',
                'date',
                'after_or_equal:first_installment_date',
            ],

            'preventa_installments_count' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'is_custom_plan' => [
                'boolean',
            ],

            'is_special_lot' => [
                'boolean',
            ],

            'promises' => [
                'nullable',
                'array',
            ],

            'promises.*.expected_date' => [
                'required_with:promises',
                'date',
            ],

            'promises.*.expected_amount' => [
                'required_with:promises',
                'numeric',
                'min:1',
            ],

            'promises.*.description' => [
                'required_with:promises',
                'string',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_name.required_without' =>
                'Indica el nombre del cliente o un customer_id válido.',

            'customer_document.required_without' =>
                'Indica el documento del cliente o un customer_id válido.',

            'customer_phone.required_without' =>
                'Indica el teléfono del cliente o un customer_id válido.',

            'customer_id.exists' =>
                'El cliente indicado no existe.',

            'lot_id.exists' =>
                'El lote indicado no existe.',

            'contract_number.unique' =>
                'El número de contrato ya está registrado.',

            'co_titular_ids.*.distinct' =>
                'No se puede repetir un cotitular.',

            'co_titular_ids.*.exists' =>
                'Uno de los cotitulares indicados no existe.',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $customerId = (int) $this->input('customer_id');

                $coTitularIds = array_map(
                    'intval',
                    (array) $this->input('co_titular_ids', [])
                );

                if (
                    $customerId > 0
                    && in_array($customerId, $coTitularIds, true)
                ) {
                    $validator->errors()->add(
                        'co_titular_ids',
                        'Un mismo cliente no puede aparecer más de una vez entre los titulares.'
                    );
                }

                $isSpecialLot = $this->boolean('is_special_lot');

                if ($isSpecialLot) {
                    return;
                }

                $salePrice = (float) ($this->input('sale_price') ?? 0);
                $downPayment = (float) ($this->input('down_payment_pactada') ?? 0);
                $termMonths = (int) ($this->input('term_months') ?? 0);

                if ($downPayment > $salePrice) {
                    $validator->errors()->add(
                        'down_payment_pactada',
                        'La cuota inicial pactada no puede ser superior al precio de venta.'
                    );
                }

                if ($termMonths < 1) {
                    $validator->errors()->add(
                        'term_months',
                        'El plazo debe ser mayor a cero para un lote financiado.'
                    );
                }
            },
        ];
    }
 
}