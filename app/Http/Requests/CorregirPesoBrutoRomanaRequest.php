<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorregirPesoBrutoRomanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('corregir-peso-bruto-romana') === true;
    }

    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => ['required', 'integer', 'min:1'],
            'peso_bruto' => ['required', 'numeric', 'min:0.001', 'max:200000', 'decimal:0,3'],
            'pesaje_id' => ['nullable', 'uuid'],
            'motivo_correccion' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'peso_bruto.required' => 'Ingresa el peso bruto corregido.',
            'peso_bruto.min' => 'El peso bruto debe ser mayor que cero.',
            'peso_bruto.max' => 'El peso bruto supera el máximo operacional de 200.000 kg.',
            'peso_bruto.decimal' => 'El peso bruto admite hasta tres decimales.',
            'motivo_correccion.required' => 'Explica el motivo de la corrección de peso.',
            'motivo_correccion.min' => 'El motivo debe tener al menos 10 caracteres.',
            'version_conocida.required' => 'Actualiza el expediente antes de corregir el peso.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['motivo_correccion' => trim((string) $this->input('motivo_correccion'))]);
    }
}
