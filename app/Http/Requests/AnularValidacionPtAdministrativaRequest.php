<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnularValidacionPtAdministrativaRequest extends FormRequest
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
            'motivo_categoria' => ['required', Rule::in(AnularValidacionPalletRequest::CATEGORIAS)],
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'motivo_categoria.required' => 'Elige la categoría del motivo.',
            'motivo.required' => 'Indica el motivo de la anulación.',
            'motivo.min' => 'Describe el motivo con al menos 10 caracteres.',
        ];
    }
}
