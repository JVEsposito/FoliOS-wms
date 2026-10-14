<?php

namespace App\Http\Requests;

use App\Enums\ModoOperacionTransformacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IniciarOrdenTransformacionMaterialRequest extends FormRequest
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
            // Modo cuadratura: se retiran todos los folios reservados al iniciar.
            'modo_operacion' => ['nullable', Rule::enum(ModoOperacionTransformacion::class)],
            'folios_retirados' => ['required_if:modo_operacion,cuadratura_cierre', 'array', 'max:200'],
            'folios_retirados.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
