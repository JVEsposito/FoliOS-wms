<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarPlanoPlantaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'version_esperada' => ['required', 'integer', 'min:0'],
            'nombre' => ['required', 'string', 'max:120'],
            // Ancho/alto del dibujo: 0,5 es un plano el doble de alto que ancho; 2,5, muy apaisado.
            'proporcion' => ['sometimes', 'nullable', 'numeric', 'between:0.5,2.5'],
            'elementos' => ['present', 'array', 'max:150'],
            'elementos.*.id' => ['required', 'uuid', 'distinct'],
            'elementos.*.tipo' => ['required', Rule::in(['camara', 'tunel', 'anden', 'almacen', 'zona', 'pasillo'])],
            'elementos.*.referencia_id' => ['nullable', 'uuid'],
            'elementos.*.nombre' => ['required', 'string', 'max:100'],
            'elementos.*.categoria' => ['nullable', 'string', 'max:30'],
            'elementos.*.x' => ['required', 'integer', 'between:0,10000'],
            'elementos.*.y' => ['required', 'integer', 'between:0,10000'],
            'elementos.*.ancho' => ['required', 'integer', 'between:100,10000'],
            'elementos.*.alto' => ['required', 'integer', 'between:100,10000'],
            'elementos.*.rotacion' => ['required', Rule::in([0, 90, 180, 270])],
            'conexiones' => ['sometimes', 'array', 'max:400'],
            'conexiones.*.id' => ['required', 'uuid', 'distinct'],
            'conexiones.*.desde' => ['required', 'uuid'],
            'conexiones.*.hacia' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'elementos.max' => 'El plano admite hasta 150 elementos.',
            'proporcion.between' => 'La proporción del plano debe estar entre 0,5 (vertical) y 2,5 (apaisado).',
            'elementos.*.id.distinct' => 'Cada elemento del plano debe tener un identificador único.',
            'conexiones.max' => 'El plano admite hasta 400 conexiones.',
            'conexiones.*.id.distinct' => 'Cada conexión debe tener un identificador único.',
            'elementos.*.ancho.between' => 'El ancho de cada elemento debe permanecer dentro del plano.',
            'elementos.*.alto.between' => 'El alto de cada elemento debe permanecer dentro del plano.',
        ];
    }
}
