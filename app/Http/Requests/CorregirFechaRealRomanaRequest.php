<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorregirFechaRealRomanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('corregir-fecha-real-romana') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => ['required', 'integer', 'min:1'],
            'ingreso_fecha' => ['required', 'date_format:Y-m-d'],
            'ingreso_hora' => ['required', 'date_format:H:i'],
            'salida_fecha' => ['nullable', 'date_format:Y-m-d', 'required_with:salida_hora'],
            'salida_hora' => ['nullable', 'date_format:H:i', 'required_with:salida_fecha'],
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ingreso_fecha.required' => 'Indica la fecha real de ingreso del camión.',
            'ingreso_hora.required' => 'Indica la hora real de ingreso del camión.',
            'salida_fecha.required_with' => 'Indica la fecha real de salida del camión.',
            'salida_hora.required_with' => 'Indica la hora real de salida del camión.',
            'motivo.required' => 'Explica por qué se registró en otro momento.',
            'motivo.min' => 'El motivo debe tener al menos 10 caracteres.',
            'version_conocida.required' => 'Actualiza el expediente antes de corregir la fecha.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['motivo' => trim((string) $this->input('motivo'))]);
    }
}
