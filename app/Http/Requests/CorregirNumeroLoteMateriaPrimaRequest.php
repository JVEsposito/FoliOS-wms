<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorregirNumeroLoteMateriaPrimaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('corregir-numero-lote-mp') === true;
    }

    public function rules(): array
    {
        return ['operacion_id' => ['required', 'uuid'], 'version_conocida' => ['required', 'integer', 'min:1'],
            'numero_lote' => ['required', 'string', 'max:80'], 'motivo' => ['required', 'string', 'min:10', 'max:2000']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['numero_lote' => mb_strtoupper(trim((string) $this->input('numero_lote'))),
            'motivo' => trim((string) $this->input('motivo'))]);
    }
}
