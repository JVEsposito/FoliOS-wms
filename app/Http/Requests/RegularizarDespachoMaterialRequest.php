<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegularizarDespachoMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('autorizar-despachos-tardios-materiales') === true;
    }

    public function rules(): array
    {
        return [
            'retiros' => ['prohibited'],
            'cantidad' => ['prohibited'],
            'destino_material_id' => ['prohibited'],
            'fecha_despacho' => ['prohibited'],
            'provisional' => ['prohibited'],
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => ['required', 'integer', 'min:1'],
            'numero_documento' => ['required', 'string', 'max:50'],
            'fecha_documento' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
            'aceptar_fuera_plazo' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['numero_documento' => trim((string) $this->input('numero_documento')), 'motivo' => trim((string) $this->input('motivo'))]);
    }
}
