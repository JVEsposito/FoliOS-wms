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
            'linea_proceso' => ['required', 'string', 'max:50'],
            'turno' => ['required', 'string', Rule::in(['A', 'B'])],
            'numero_orden' => ['required', 'string', 'max:80'],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
