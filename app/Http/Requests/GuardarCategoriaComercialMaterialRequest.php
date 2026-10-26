<?php

namespace App\Http\Requests;

use App\Services\Materiales\NombreClasificacionMaterial;
use Illuminate\Foundation\Http\FormRequest;

class GuardarCategoriaComercialMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administrar-catalogos-materiales') === true;
    }

    public function rules(): array
    {
        return ['nombre' => ['required', 'string', 'min:1', 'max:100'], 'activo' => ['required', 'boolean']];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nombre'))) {
            $this->merge(['nombre' => NombreClasificacionMaterial::mayusculas($this->input('nombre'))]);
        }
    }
}
