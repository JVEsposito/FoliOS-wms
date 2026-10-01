<?php

namespace App\Http\Requests;

class CorregirSalidaEnvasesRomanaRequest extends CerrarRecepcionRomanaRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('corregir-recepciones-romana') === true;
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'version_conocida' => ['required', 'integer', 'min:1'],
            'motivo_correccion' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
