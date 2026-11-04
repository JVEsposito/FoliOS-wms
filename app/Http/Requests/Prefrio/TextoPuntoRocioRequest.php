<?php

namespace App\Http\Requests\Prefrio;

use Illuminate\Foundation\Http\FormRequest;

/** Acción correctiva o motivo de anulación de una medición. */
class TextoPuntoRocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('supervisar-prefrio') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['texto' => ['required', 'string', 'min:5', 'max:1000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'texto.required' => 'Describe la acción o el motivo.',
            'texto.min' => 'Escribe al menos 5 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['texto' => trim((string) $this->input('texto'))]);
    }
}
