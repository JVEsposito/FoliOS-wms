<?php

namespace App\Http\Requests;

use App\Enums\TipoEnvaseRomana;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarPesajeEnvasesRomanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operar-romana') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            // Una tanda puede mezclar tipos: cantidad por tipo de envase sobre la báscula.
            'envases' => ['required_without:cantidad_envases', 'array', 'min:1', 'max:'.count(TipoEnvaseRomana::cases())],
            'envases.*.tipo_envase' => ['required', 'distinct', Rule::enum(TipoEnvaseRomana::class)],
            'envases.*.cantidad' => ['required', 'integer', 'min:0', 'max:100000'],
            // Contrato anterior, de un solo tipo de envase.
            'cantidad_envases' => ['required_without:envases', 'prohibits:envases', 'integer', 'min:1', 'max:100000'],
            'peso_bruto' => ['required', 'numeric', 'min:0.001', 'max:200000', 'decimal:0,3'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'envases.required_without' => 'Indica cuántos envases de cada tipo contiene esta lectura.',
            'envases.*.tipo_envase.distinct' => 'Cada tipo de envase puede indicarse solo una vez por tanda.',
            'envases.*.cantidad.min' => 'La cantidad por tipo de envase no puede ser negativa.',
            'cantidad_envases.required_without' => 'Indica cuántos envases contiene esta lectura.',
            'cantidad_envases.prohibits' => 'Indica la cantidad por tipo de envase o el total de la lectura, no ambos.',
            'cantidad_envases.min' => 'Cada lectura debe contener al menos un envase.',
            'peso_bruto.required' => 'Ingresa el peso bruto observado para este grupo.',
            'peso_bruto.min' => 'El peso bruto de la lectura debe ser mayor que cero.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'observacion' => filled($this->input('observacion'))
                ? trim((string) $this->input('observacion'))
                : null,
        ]);
    }
}
