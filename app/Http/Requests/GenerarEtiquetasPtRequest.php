<?php

namespace App\Http\Requests;

use App\Models\PersonalAccessToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerarEtiquetasPtRequest extends FormRequest
{
    public function authorize(): bool
    {
        $token = $this->user()?->currentAccessToken();

        return $this->user()?->can('consultar-validaciones-pallet') === true
            && $token instanceof PersonalAccessToken
            && $token->dispositivo_id === null
            && in_array('oficina', $token->abilities, true);
    }

    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'temporada_id' => ['required', 'uuid', 'exists:temporadas,id'],
            'tipo' => ['required', Rule::in(['folio', 'ventana'])],
            'copias' => ['required', 'integer', 'between:1,10'],
            'motivo_reimpresion' => ['nullable', 'string', 'min:5', 'max:1000'],
            'validaciones' => ['required', 'array', 'min:1', 'max:50'],
            'validaciones.*.id' => ['required', 'uuid', 'distinct'],
            'validaciones.*.version' => ['required', 'string', 'size:64'],
        ];
    }
}
