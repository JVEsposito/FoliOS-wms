<?php

namespace App\Http\Requests\Prefrio;

use App\Enums\EtapaMedicionPuntoRocio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarMargenesPuntoRocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('supervisar-prefrio') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'margenes' => ['required', 'array', 'min:1'],
            'margenes.*.etapa' => ['required', 'distinct', Rule::enum(EtapaMedicionPuntoRocio::class)],
            'margenes.*.margen_minimo_c' => ['required', 'numeric', 'min:0', 'max:10'],
            'margenes.*.version_conocida' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'margenes.*.margen_minimo_c.min' => 'El margen mínimo no puede ser negativo.',
            'margenes.*.margen_minimo_c.max' => 'El margen mínimo no puede superar 10 °C.',
        ];
    }
}
