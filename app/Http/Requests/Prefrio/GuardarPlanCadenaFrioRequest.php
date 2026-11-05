<?php

namespace App\Http\Requests\Prefrio;

use App\Enums\EtapaMedicionPuntoRocio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarPlanCadenaFrioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('supervisar-prefrio') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $cadena = array_map(fn (EtapaMedicionPuntoRocio $etapa): string => $etapa->value, EtapaMedicionPuntoRocio::cadena());

        return [
            'etapas' => ['required', 'array', 'min:1'],
            'etapas.*.etapa' => ['required', 'distinct', Rule::in($cadena)],
            'etapas.*.temperatura_aire_c' => ['nullable', 'numeric', 'min:-30', 'max:60'],
            'etapas.*.temperatura_fruta_c' => ['nullable', 'numeric', 'min:-30', 'max:60'],
            'etapas.*.version_conocida' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'etapas.*.etapa.in' => 'Esa etapa no forma parte de la cadena de frío.',
            'etapas.*.temperatura_aire_c.min' => 'La T° del aire debe estar entre -30 y 60 °C.',
            'etapas.*.temperatura_aire_c.max' => 'La T° del aire debe estar entre -30 y 60 °C.',
            'etapas.*.temperatura_fruta_c.min' => 'La T° de la fruta debe estar entre -30 y 60 °C.',
            'etapas.*.temperatura_fruta_c.max' => 'La T° de la fruta debe estar entre -30 y 60 °C.',
        ];
    }
}
