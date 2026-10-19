<?php

namespace App\Finanzas\Http\Requests;

use App\Finanzas\Models\Moneda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta (POST) o edición (PUT) de una moneda. El código no cambia después de creada. */
class GuardarMonedaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('codigo'))) {
            $this->merge(['codigo' => mb_strtoupper(trim($this->input('codigo')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $alta = $this->isMethod('post') ? [
            'codigo' => ['required', 'string', 'regex:/^[A-Z0-9]{2,10}$/', Rule::unique(Moneda::class, 'codigo')],
        ] : [
            'activa' => ['sometimes', 'boolean'],
        ];

        return [
            ...$alta,
            'nombre' => ['required', 'string', 'max:60'],
            'simbolo' => ['required', 'string', 'max:10'],
            'decimales' => ['required', 'integer', 'between:0,6'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Indique :attribute.',
            'string' => ':Attribute debe ser texto.',
            'max' => ':Attribute no puede superar :max caracteres.',
            'boolean' => 'El estado de la moneda no es válido.',
            'codigo.regex' => 'El código debe tener de 2 a 10 letras o números, sin espacios (por ejemplo, CLP, UF o USD).',
            'codigo.unique' => 'Ya existe una moneda con ese código.',
            'decimales.integer' => 'Los decimales deben ser un número entero.',
            'decimales.between' => 'Los decimales deben estar entre 0 y 6.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'codigo' => 'el código',
            'nombre' => 'el nombre',
            'simbolo' => 'el símbolo',
            'decimales' => 'los decimales',
        ];
    }
}
