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

        return $this->user()?->can('imprimir-etiquetas-pt') === true
            && $token instanceof PersonalAccessToken
            && $token->dispositivo_id === null
            && in_array('oficina', $token->abilities, true);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('copias') && $this->input('tipo') === 'planta') {
            $this->merge(['copias' => 4]);
        }
    }

    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'temporada_id' => ['required', 'uuid', 'exists:temporadas,id'],
            'tipo' => ['required', Rule::in(['folio', 'ventana', 'planta'])],
            'copias' => ['required', 'integer', 'between:1,10'],
            'motivo_reimpresion' => ['nullable', 'string', 'min:5', 'max:1000'],
            'folios' => ['required_without:validaciones', 'prohibits:validaciones', 'array', 'min:1', 'max:50'],
            'folios.*.id' => ['required', 'uuid', 'distinct'],
            'folios.*.version' => ['required', 'string', 'size:64'],
            'validaciones' => ['required_without:folios', 'array', 'min:1', 'max:50'],
            'validaciones.*.id' => ['required', 'uuid', 'distinct'],
            'validaciones.*.version' => ['required', 'string', 'size:64'],
        ];
    }
}
