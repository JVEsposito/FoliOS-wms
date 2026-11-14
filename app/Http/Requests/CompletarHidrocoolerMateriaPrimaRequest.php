<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompletarHidrocoolerMateriaPrimaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operar-hidrocooler-materia-prima') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'termino_at' => ['required', 'date', 'before_or_equal:now'],
            'temperatura_c' => ['required', 'numeric', 'between:-20,50', 'decimal:0,2'],
            'temperatura_agua_final_c' => ['nullable', 'numeric', 'between:-20,50', 'decimal:0,2'],
            // Cloro, pH, dosificador y control final son del registro completo: el servicio los exige
            // solo si el ciclo es oficial. El registro de patio pide temperaturas y estado del agua.
            'cloro_libre_final_ppm' => ['nullable', 'numeric', 'between:0,500', 'decimal:0,2'],
            'ph_agua_final' => ['nullable', 'numeric', 'between:0,14', 'decimal:0,2'],
            'condicion_visual_agua_final' => ['required', Rule::in(['conforme', 'no_conforme'])],
            'dosificador_operativo_final' => ['nullable', 'boolean'],
            'control_final_conforme' => ['nullable', 'boolean'],
            'destino_salida' => ['required', Rule::in(['camara', 'proceso'])],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'accion_correctiva' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'observacion' => filled($this->input('observacion'))
                ? trim((string) $this->input('observacion'))
                : null,
            'accion_correctiva' => filled($this->input('accion_correctiva'))
                ? trim((string) $this->input('accion_correctiva'))
                : null,
        ]);
    }
}
