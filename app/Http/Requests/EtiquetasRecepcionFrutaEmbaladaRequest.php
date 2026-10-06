<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class EtiquetasRecepcionFrutaEmbaladaRequest extends GenerarEtiquetasPtRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-recepciones-fruta-embalada') === true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('tipo')) {
            $this->merge(['tipo' => 'planta']);
        }
        parent::prepareForValidation();
    }

    public function rules(): array
    {
        return [...parent::rules(), 'tipo' => ['required', Rule::in(['planta'])],
            'folios' => ['required', 'array', 'min:1', 'max:50'], 'validaciones' => ['prohibited']];
    }
}
