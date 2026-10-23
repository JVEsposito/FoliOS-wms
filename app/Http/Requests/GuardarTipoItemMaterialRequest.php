<?php

namespace App\Http\Requests;

use App\Enums\CategoriaOperacionalMaterial;
use Illuminate\Validation\Rule;

class GuardarTipoItemMaterialRequest extends GuardarCategoriaComercialMaterialRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'categoria_operacional' => ['required', Rule::enum(CategoriaOperacionalMaterial::class)]];
    }
}
