<?php

namespace App\Http\Requests;

class EmitirEtiquetasMuestreoSagRequest extends DatosDiagramaPalletsSagRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'formato' => ['required', 'in:a4,rollo'],
        ];
    }
}
