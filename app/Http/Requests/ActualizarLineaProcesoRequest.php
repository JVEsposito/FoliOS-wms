<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarLineaProcesoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('configurar-lineas-proceso') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'string', 'min:1', 'max:60'],
            'rendimiento_kg_hora' => ['sometimes', 'numeric', 'min:100', 'max:100000'],
            'activa' => ['sometimes', 'boolean'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rendimiento_kg_hora.min' => 'El rendimiento debe ser de al menos 100 kg por hora.',
            'rendimiento_kg_hora.max' => 'El rendimiento no puede superar 100.000 kg por hora.',
        ];
    }
}
