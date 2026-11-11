<?php

namespace App\Http\Requests;

use App\Services\Folios\ServicioCambioAtributosPt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CambiarCondicionTermicaPtRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cambiar-atributos-pt') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'condicion' => ['required', Rule::in(collect(ServicioCambioAtributosPt::CONDICIONES)->map->value->all())],
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'condicion.in' => 'Elige una condición de prefrío válida.',
            'motivo.required' => 'Indica el motivo del cambio.',
            'motivo.min' => 'Describe el motivo con al menos 10 caracteres.',
        ];
    }
}
