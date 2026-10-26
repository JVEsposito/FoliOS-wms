<?php

namespace App\Http\Requests;

use App\Services\Materiales\ValorCompraMaterial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValorizarRecepcionMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('valorizar-recepciones-materiales') === true;
    }

    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
            'detalles' => ['required', 'array', 'min:1', 'max:100'],
            'detalles.*.id' => ['required', 'uuid', 'distinct'],
            'detalles.*.precio_unitario_neto' => ['present', 'nullable', 'numeric', 'min:0', 'max:999999999.999999', 'decimal:0,6'],
            'detalles.*.moneda' => ['present', 'nullable', Rule::in(array_keys(ValorCompraMaterial::MONEDAS))],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['motivo' => trim((string) $this->input('motivo'))]);
    }
}
