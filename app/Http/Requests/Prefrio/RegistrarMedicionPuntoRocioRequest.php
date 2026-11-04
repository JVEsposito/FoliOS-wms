<?php

namespace App\Http\Requests\Prefrio;

use App\Enums\EtapaMedicionPuntoRocio;
use App\Enums\GotasVisiblesPuntoRocio;
use App\Enums\TipoPuntoControlRocio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarMedicionPuntoRocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operar-prefrio') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'etapa' => ['required', Rule::enum(EtapaMedicionPuntoRocio::class)],
            'punto_tipo' => ['required', Rule::enum(TipoPuntoControlRocio::class)],
            'punto_id' => ['required', 'uuid'],
            'referencia' => ['nullable', 'string', 'max:80'],
            'temperatura_ambiente_c' => ['required', 'numeric', 'min:-30', 'max:60'],
            'humedad_relativa' => ['required', 'numeric', 'gt:0', 'max:100'],
            'temperatura_superficie_c' => ['nullable', 'numeric', 'min:-30', 'max:60'],
            'gotas_visibles' => ['required', Rule::enum(GotasVisiblesPuntoRocio::class)],
            'observacion' => ['nullable', 'string', 'max:1000'],
            'medido_at' => ['required', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'punto_id.required' => 'Selecciona dónde se tomó la medición.',
            'temperatura_ambiente_c.required' => 'Ingresa la temperatura del ambiente.',
            'humedad_relativa.required' => 'Ingresa la humedad relativa.',
            'humedad_relativa.gt' => 'La humedad relativa debe ser mayor que 0 %.',
            'humedad_relativa.max' => 'La humedad relativa no puede superar 100 %.',
            'gotas_visibles.required' => 'Indica si hay gotas visibles.',
        ];
    }
}
