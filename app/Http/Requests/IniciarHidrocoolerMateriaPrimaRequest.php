<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IniciarHidrocoolerMateriaPrimaRequest extends FormRequest
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
            'equipo' => ['required', 'string', 'max:100'],
            'turno' => ['required', Rule::in(['A', 'B'])],
            'cantidad_bombas_funcionando' => ['required', 'integer', 'between:1,20'],
            'inicio_at' => ['required', 'date', 'before_or_equal:now'],
            'temperatura_inicial_c' => ['required', 'numeric', 'between:-20,50', 'decimal:0,2'],
            'temperatura_objetivo_c' => ['required', 'numeric', 'between:-20,50', 'decimal:0,2'],
            'temperatura_agua_inicial_c' => ['nullable', 'numeric', 'between:-20,50', 'decimal:0,2'],
            'cloro_libre_ppm' => ['required', 'numeric', 'between:0,500', 'decimal:0,2'],
            'temperatura_ambiente_c' => ['required', 'numeric', 'between:-50,70', 'decimal:0,2'],
            'humedad_relativa_pct' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'pozo_accutab_mv' => ['required', 'numeric', 'between:-9999999,9999999', 'decimal:0,2'],
            'recarga_pastilla' => ['required', 'boolean'],
            'correccion_cloro_ppm' => ['nullable', 'numeric', 'between:0,500', 'decimal:0,2'],
            'aplicacion_producto' => ['required', 'boolean'],
            'producto_hidrocooler_id' => [Rule::requiredIf(fn () => $this->boolean('aplicacion_producto')), 'nullable', 'uuid', 'exists:productos_hidrocooler,id'],
            'producto_dosis' => [Rule::requiredIf(fn () => $this->boolean('aplicacion_producto')), 'nullable', 'numeric', 'gt:0', 'max:99999999', 'decimal:0,4'],
            'producto_unidad_dosis' => [Rule::requiredIf(fn () => $this->boolean('aplicacion_producto')), 'nullable', 'string', 'max:30'],
            'ph_agua' => ['required', 'numeric', 'between:0,14', 'decimal:0,2'],
            'control_inicial_conforme' => ['required', 'boolean'],
            'condicion_visual_agua' => ['required', Rule::in(['conforme', 'no_conforme'])],
            'dosificador_operativo' => ['required', 'boolean'],
            'manejo_agua' => ['required', Rule::in(['sin_novedad', 'filtrado', 'recambio'])],
            'observacion_inicio' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'equipo' => trim((string) $this->input('equipo')),
            'turno' => strtoupper(trim((string) $this->input('turno'))),
            'observacion_inicio' => filled($this->input('observacion_inicio'))
                ? trim((string) $this->input('observacion_inicio'))
                : null,
        ]);
    }
}
