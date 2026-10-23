<?php

namespace App\Http\Requests;

use App\Services\Materiales\ServicioArmadoMaterial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrearOrdenTransformacionMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-transformaciones-materiales') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Con tarja de armado, la línea es la máquina y el turno entra al lote del pallet.
        $armado = app(ServicioArmadoMaterial::class)->activo();

        return [
            'operacion_id' => ['required', 'uuid'],
            'almacen_origen_id' => ['nullable', 'uuid', 'exists:destinos_materiales,id'],
            'version_receta_material_id' => [
                'required',
                'uuid',
                'exists:versiones_recetas_materiales,id',
            ],
            'cantidad_planificada_salida' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'linea' => $armado
                ? ['required', 'string', 'regex:/^\s*(0?[1-9]|[1-9][0-9])\s*$/']
                : ['nullable', 'string', 'max:100'],
            'turno' => $armado
                ? ['required', 'string', Rule::in(array_keys(ServicioArmadoMaterial::TURNOS))]
                : ['nullable', 'string', 'max:80'],
            'fecha_operacional' => ['required', 'date'],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'linea.required' => 'Indica la máquina de armado.',
            'linea.regex' => 'La máquina de armado es un número de 1 a 99.',
            'turno.required' => 'Indica el turno de armado: 01 (día) o 02 (tarde).',
            'turno.in' => 'El turno de armado es 01 (día) o 02 (tarde).',
        ];
    }
}
