<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CerrarLoteTransformacionMaterialRequest extends FormRequest
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
            'cantidad_real_salida' => ['required', 'numeric', 'gt:0', 'max:99999999999.999'],
            // Folio de salida reservado e impreso al planificar; si no viene, se usa el siguiente.
            'folio_salida' => ['nullable', 'string', 'max:64'],
            'consumos' => ['required', 'array', 'min:1', 'max:100'],
            'consumos.*.folio_id' => ['required', 'uuid', 'distinct'],
            'consumos.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:99999999999.999'],
            'consumos.*.motivo_desviacion_fifo' => ['nullable', 'string', 'min:5', 'max:1000'],
        ];
    }
}
