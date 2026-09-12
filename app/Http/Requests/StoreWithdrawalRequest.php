<?php

namespace App\Http\Requests;

use App\Enums\WithdrawalCause;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_id' => [
                'required',
                'integer',
                'exists:contracts,id',
            ],

            'request_date' => [
                'required',
                'date',
            ],

            'cause' => [
                'required',
                Rule::enum(WithdrawalCause::class),
            ],

            'retention_percentage' => [
                'nullable',
                'numeric',
                'between:0,100',
            ],

            'observations' => [
                'nullable',
                'string',
            ],

            'modification_justification' => [
                'nullable',
                'string',
            ],
        ];
    }
}