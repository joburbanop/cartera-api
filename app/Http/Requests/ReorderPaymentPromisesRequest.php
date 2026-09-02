<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderPaymentPromisesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('promises')) {
            return;
        }

        $payload = $this->all();

        if (array_is_list($payload)) {
            $this->replace(['promises' => $payload]);
        }
    }

    public function rules(): array
    {
        return [
            'promises' => ['required', 'array', 'min:1'],
            'promises.*.id' => ['required', 'integer'],
            'promises.*.expected_date' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'promises.required' => 'Debe enviar el cronograma ordenado.',
            'promises.*.id.required' => 'Cada promesa debe incluir su id.',
            'promises.*.expected_date.required' => 'Cada promesa debe incluir su fecha esperada.',
        ];
    }
}
