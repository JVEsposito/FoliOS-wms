<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorregirEmbalajeEntregaMpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-ordenes-proceso') === true;
    }

    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_embalaje' => ['required', 'integer', 'min:0'],
            'embalaje' => ['required', 'string', 'max:150'],
            'kilos_por_caja' => ['nullable', 'numeric', 'min:0.001', 'max:9999999.999'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
            'cantidad_envases' => ['prohibited'],
            'bins_programados' => ['prohibited'],
        ];
    }
}
