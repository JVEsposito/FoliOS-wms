<?php

namespace App\Http\Requests;

use App\Enums\TipoEnvaseRomana;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Corrección transversal de tara de una recepción de fruta: solo administración. */
class CorregirTaraRomanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('corregir-tara-romana') === true;
    }

    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => ['required', 'integer', 'min:1'],
            // Tara del camión vacío; solo existe en recepciones con destare por camión.
            'peso_tara' => ['nullable', 'numeric', 'min:1', 'max:200000', 'decimal:0,2'],
            'taras_envases' => ['nullable', 'array', 'max:'.count(TipoEnvaseRomana::cases())],
            'taras_envases.*.tipo_envase' => ['required', 'distinct', Rule::enum(TipoEnvaseRomana::class)],
            'taras_envases.*.tara_unitaria' => ['required', 'numeric', 'min:0.001', 'max:1000', 'decimal:0,3'],
            'motivo_correccion' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'peso_tara.min' => 'La tara del camión debe ser mayor que cero.',
            'peso_tara.max' => 'La tara del camión supera el máximo operacional de 200.000 kg.',
            'peso_tara.decimal' => 'La tara del camión admite hasta dos decimales.',
            'taras_envases.*.tipo_envase.distinct' => 'Cada tipo de envase puede corregir su tara solo una vez.',
            'taras_envases.*.tara_unitaria.required' => 'Ingresa la tara unitaria de cada envase.',
            'taras_envases.*.tara_unitaria.min' => 'La tara unitaria de cada envase debe ser mayor que cero.',
            'taras_envases.*.tara_unitaria.max' => 'La tara unitaria no puede superar 1.000 kg.',
            'taras_envases.*.tara_unitaria.decimal' => 'La tara unitaria admite hasta tres decimales.',
            'motivo_correccion.required' => 'Explica el motivo de la corrección de tara.',
            'motivo_correccion.min' => 'El motivo debe tener al menos 10 caracteres.',
            'version_conocida.required' => 'Actualiza el expediente antes de corregir la tara.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['motivo_correccion' => trim((string) $this->input('motivo_correccion'))]);
    }
}
