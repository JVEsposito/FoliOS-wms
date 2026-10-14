<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CerrarOrdenTransformacionMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operar-transformaciones-materiales') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => ['required', 'integer', 'min:1'],
            'motivo_desviacion' => ['nullable', 'string', 'min:5', 'max:1000'],
            // Modo cuadratura: folios creados que vio el camarero y merma/sin armar por componente.
            'folios_creados' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'cuadratura' => ['nullable', 'array', 'max:50'],
            'cuadratura.*.item_id' => ['required', 'uuid', 'distinct'],
            'cuadratura.*.cantidad_merma' => ['required', 'numeric', 'min:0', 'max:99999999999.999'],
            'cuadratura.*.cantidad_sin_armar' => ['required', 'numeric', 'min:0', 'max:99999999999.999'],
        ];
    }
}
