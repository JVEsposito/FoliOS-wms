<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EliminarFolioManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('eliminar-folios-manuales') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Indica por qué se elimina el folio.',
            'motivo.min' => 'El motivo debe tener al menos 10 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['motivo' => trim((string) $this->input('motivo'))]);
    }
}
