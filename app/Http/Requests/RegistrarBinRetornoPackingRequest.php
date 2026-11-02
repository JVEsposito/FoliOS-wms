<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarBinRetornoPackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'kilos_totales' => ['required', 'numeric', 'min:0.001', 'max:999999999.999'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'origenes' => ['required', 'array', 'min:1', 'max:20'],
            'origenes.*.lote_materia_prima_id' => ['required', 'uuid', 'exists:lotes_materia_prima,id'],
            'origenes.*.numero_orden' => ['required', 'string', 'max:80'],
            'origenes.*.linea_proceso' => ['required', 'string', 'max:60'],
            'origenes.*.turno' => ['required', 'string', Rule::in(['A', 'B'])],
            'origenes.*.kilos_aportados' => ['required', 'numeric', 'min:0.001', 'max:999999999.999'],
        ];
    }
}
