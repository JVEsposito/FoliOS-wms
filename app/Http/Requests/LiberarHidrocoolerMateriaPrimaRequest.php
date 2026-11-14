<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LiberarHidrocoolerMateriaPrimaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('supervisar-lotes-materia-prima') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'temperatura_verificacion_c' => ['required', 'numeric', 'between:-20,50', 'decimal:0,2'],
            // Obligatorios para ciclos oficiales (lo exige el servicio); opcionales en el registro de patio.
            'cloro_libre_verificacion_ppm' => ['nullable', 'numeric', 'between:0,500', 'decimal:0,2'],
            'ph_agua_verificacion' => ['nullable', 'numeric', 'between:0,14', 'decimal:0,2'],
            'control_verificacion_conforme' => ['required', 'accepted'],
            'evaluacion_producto' => ['required', 'string', 'min:10', 'max:2000'],
            'verificacion_liberacion' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['evaluacion_producto', 'verificacion_liberacion'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => trim($this->input($campo))]);
            }
        }
    }
}
