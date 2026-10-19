<?php

namespace App\Finanzas\Http\Requests;

use App\Finanzas\Tarifario\CatalogoServicios;
use App\Finanzas\Tarifario\Precio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta (POST) o edición (PUT) de un precio. Al editar no cambian el cliente, el servicio ni la
 * unidad: esos campos se ignoran. Las reglas de vigencia se validan en GestorTarifas.
 */
class GuardarTarifaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalizados = [];
        if (is_string($this->input('precio'))) {
            // Se acepta la coma decimal que se escribe en Chile; no los separadores de miles.
            $normalizados['precio'] = str_replace(',', '.', trim($this->input('precio')));
        }
        if (is_string($this->input('moneda'))) {
            $normalizados['moneda'] = mb_strtoupper(trim($this->input('moneda')));
        }

        $this->merge($normalizados);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $alta = $this->isMethod('post') ? [
            'cliente_id' => ['nullable', 'string', 'uuid'],
            'servicio' => ['required', 'string', Rule::in(app(CatalogoServicios::class)->claves())],
            'unidad' => ['required', 'string', 'max:40'],
            'cerrar_anterior' => ['sometimes', 'boolean'],
        ] : [];

        return [
            ...$alta,
            'moneda' => ['required', 'string', 'max:10'],
            'precio' => ['required', 'regex:'.Precio::FORMATO],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'vigente_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:vigente_desde'],
            'nota' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Indique :attribute.',
            'string' => ':Attribute debe ser texto.',
            'max' => ':Attribute no puede superar :max caracteres.',
            'boolean' => ':Attribute no es válida.',
            'uuid' => ':Attribute no es válido.',
            'date_format' => ':Attribute no es una fecha válida.',
            'servicio.in' => 'El servicio elegido no existe.',
            'precio.regex' => 'El precio debe ser un número positivo, con hasta 6 decimales y sin separador de miles.',
            'vigente_hasta.after_or_equal' => 'El término no puede ser anterior al inicio.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'cliente_id' => 'el cliente',
            'servicio' => 'el servicio',
            'unidad' => 'la unidad de cobro',
            'cerrar_anterior' => 'la opción de cerrar el precio anterior',
            'moneda' => 'la moneda',
            'precio' => 'el precio',
            'vigente_desde' => 'la fecha de inicio',
            'vigente_hasta' => 'la fecha de término',
            'nota' => 'la nota',
        ];
    }
}
