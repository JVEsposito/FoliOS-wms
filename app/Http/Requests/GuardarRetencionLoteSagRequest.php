<?php

namespace App\Http\Requests;

use App\Models\RetencionLoteSag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarRetencionLoteSagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-lotes-retenidos-sag') === true;
    }

    public function rules(): array
    {
        $nuevo = $this->isMethod('post');

        return [
            'operacion_id' => ['required', 'uuid'],
            'version' => [$nuevo ? 'prohibited' : 'required', 'integer', 'min:1'],
            'cliente_id' => [$nuevo ? 'required' : 'prohibited', 'uuid', 'exists:clientes,id'],
            'numero_lote' => [$nuevo ? 'required' : 'prohibited', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f]/u'],
            'estado' => ['required', Rule::in($nuevo ? array_diff(RetencionLoteSag::ESTADOS, ['liberado']) : RetencionLoteSag::ESTADOS)],
            'fecha_deteccion' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'observacion' => ['required', 'string', 'min:5', 'max:4000'],
            'referencia_informe' => ['nullable', 'string', 'max:250'],
            'todos_destinos' => ['required', 'boolean'],
            'destinos' => ['present', 'array', 'max:350'],
            'destinos.*.tipo' => ['required', Rule::in(['pais', 'bloque'])],
            'destinos.*.id' => ['required', 'uuid'],
            'resolucion' => [$nuevo && ! $this->boolean('definitivo') ? 'nullable' : 'required', 'string', 'min:5', 'max:4000'],
            'definitivo' => ['required', 'boolean'],
            'instruccion_segregacion' => ['nullable', 'string', 'min:5', 'max:2000'],
        ];
    }
}
