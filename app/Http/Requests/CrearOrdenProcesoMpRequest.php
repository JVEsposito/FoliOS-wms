<?php

namespace App\Http\Requests;

use App\Enums\TurnoProceso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CrearOrdenProcesoMpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-ordenes-proceso') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'linea_proceso_id' => ['required', 'uuid', 'exists:lineas_proceso,id'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'turno_inicio' => ['required', 'string', 'max:2'],
            ...self::reglasPlan(),
        ];
    }

    /** @return array<string, mixed> */
    public static function reglasPlan(): array
    {
        return [
            'hora_inicio' => ['required', 'date_format:H:i'],
            'colacion_desde' => ['nullable', 'date_format:H:i', 'required_with:colacion_hasta'],
            'colacion_hasta' => ['nullable', 'date_format:H:i', 'required_with:colacion_desde', 'different:colacion_desde'],
            'lotes' => ['required', 'array', 'min:1', 'max:80'],
            'lotes.*.lote_materia_prima_id' => ['required', 'uuid', 'distinct'],
            'lotes.*.numero_proceso' => ['nullable', 'string', 'max:40'],
            'lotes.*.bins_programados' => ['required', 'integer', 'min:1', 'max:100000'],
            'lotes.*.embalaje' => ['nullable', 'string', 'max:150'],
            'lotes.*.kilos_por_caja' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'lotes.*.mercado_destino' => ['nullable', 'string', 'max:150'],
            'lotes.*.comuna' => ['nullable', 'string', 'max:100'],
            'lotes.*.provincia' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->has('turno_inicio') && TurnoProceso::desdeCodigo($this->input('turno_inicio')) === null) {
                    $validator->errors()->add('turno_inicio', 'El turno debe ser A o B (T1/T2, AM/PM).');
                }
            },
        ];
    }
}
