<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TrasladarLoteMateriaPrimaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-lotes-materia-prima') === true;
    }

    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => ['required', 'integer', 'min:1'],
            'camara_origen_id' => ['required', 'uuid', 'exists:camaras,id'],
            'camara_destino_id' => ['required', 'uuid', 'different:camara_origen_id', 'exists:camaras,id'],
            'cantidad_envases' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $motivo = $this->input('motivo');
        $this->merge(['motivo' => is_string($motivo) ? trim($motivo) : $motivo]);
    }
}
