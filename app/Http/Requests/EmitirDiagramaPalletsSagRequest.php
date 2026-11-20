<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/** PDF del diagrama tal como está en pantalla, en A4 o A3 horizontal. */
class EmitirDiagramaPalletsSagRequest extends DatosDiagramaPalletsSagRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'papel' => ['required', Rule::in(['a4', 'a3'])],
            'mostrar_numeros' => ['required', 'boolean'],
        ];
    }
}
