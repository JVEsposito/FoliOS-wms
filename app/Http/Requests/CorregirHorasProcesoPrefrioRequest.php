<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorregirHorasProcesoPrefrioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('corregir-procesos-prefrio') === true;
    }

    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => ['required', 'integer', 'min:0'],
            'motivo' => ['required', 'string', 'max:500'],
            'eventos' => ['present', 'array', 'max:250'],
            'eventos.*.id' => ['required', 'uuid', 'distinct', 'exists:eventos_prefrio,id'],
            'eventos.*.ocurrido_at' => ['required', 'date'],
            'folios' => ['sometimes', 'array', 'max:500'],
            'folios.*.id' => ['required', 'uuid', 'distinct', 'exists:procesos_prefrio_folios,id'],
            'folios.*.cargado_at' => ['sometimes', 'required', 'date'],
            'folios.*.retirado_at' => ['sometimes', 'required', 'date'],
        ];
    }
}
