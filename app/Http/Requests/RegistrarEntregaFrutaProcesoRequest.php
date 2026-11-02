<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarEntregaFrutaProcesoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'camara_origen_id' => ['sometimes', 'nullable', 'uuid', 'exists:camaras,id'],
            'operacion_id' => ['required', 'uuid'],
            'cantidad_envases' => ['required', 'integer', 'min:1', 'max:100000'],
            'kilos_enviados' => ['nullable', 'numeric', 'min:0.001', 'max:999999999.999'],
            'orden_proceso_mp_id' => ['nullable', 'uuid'],
            'version_orden_proceso_mp_id' => ['required_with:orden_proceso_mp_id', 'nullable', 'uuid'],
            'autorizacion' => ['nullable', 'array'],
            'autorizacion.user_id' => ['required_with:autorizacion', 'integer'],
            'autorizacion.pin' => ['required_with:autorizacion', 'string', 'size:4'],
            // Sin orden: compatibilidad con la APK anterior mientras no se exija orden.
            'linea_proceso' => ['required_without:orden_proceso_mp_id', 'nullable', 'string', 'max:60'],
            'turno' => ['required', 'string', Rule::in(['A', 'B'])],
            'numero_orden' => ['required_without:orden_proceso_mp_id', 'nullable', 'string', 'max:80'],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
