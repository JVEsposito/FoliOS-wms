<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegularizarRecepcionMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('autorizar-recepciones-tardias-materiales') === true;
    }

    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => ['required', 'integer', 'min:1'],
            'numero_guia_despacho' => ['required', 'string', 'max:50'],
            'fecha_documento' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
            'aceptar_fuera_plazo' => ['sometimes', 'boolean'],
            'detalles' => ['required', 'array', 'min:1', 'max:100'],
            'detalles.*.id' => ['required', 'uuid', 'distinct'],
            'detalles.*.cantidad_documental' => ['required', 'numeric', 'gt:0', 'max:99999999999.999', 'decimal:0,3'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['numero_guia_despacho' => trim((string) $this->input('numero_guia_despacho')), 'motivo' => trim((string) $this->input('motivo'))]);
    }
}
