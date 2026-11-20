<?php

namespace App\Http\Requests;

class GuardarDiagramaPalletsSagRequest extends DatosDiagramaPalletsSagRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-inspeccion-sag') === true;
    }

    public function rules(): array
    {
        $nuevo = $this->isMethod('post');

        return [
            ...parent::rules(),
            'operacion_id' => ['required', 'uuid'],
            'version' => [$nuevo ? 'prohibited' : 'required', 'integer', 'min:1'],
            'numero' => ['required', 'string', 'max:30', 'not_regex:/[\x00-\x1f\x7f]/u'],
        ];
    }

    public function messages(): array
    {
        return ['numero.required' => 'Indica el N.º del diagrama para guardarlo.'];
    }
}
