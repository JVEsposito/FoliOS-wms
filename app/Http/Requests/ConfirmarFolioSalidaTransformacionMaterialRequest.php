<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Modo cuadratura: el camarero pistolea la etiqueta reservada y confirma la cantidad real. */
class ConfirmarFolioSalidaTransformacionMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operar-transformaciones-materiales') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => ['required', 'integer', 'min:1'],
            'folio_salida' => ['required', 'string', 'max:64'],
            'cantidad_real_salida' => ['required', 'numeric', 'gt:0', 'max:99999999999.999'],
        ];
    }
}
