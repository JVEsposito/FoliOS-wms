<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrearDespachoMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-despachos-materiales') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'destino_material_id' => ['required', 'uuid', 'exists:destinos_materiales,id'],
            'almacen_origen_id' => ['nullable', 'uuid', 'exists:destinos_materiales,id'],
            'asignado_a_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'observacion' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:item_material_id,cantidad'],
            'items.*.item_material_id' => [
                'required',
                'uuid',
                'distinct',
                'exists:items_materiales,id',
            ],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'observacion' => $this->filled('observacion')
                ? trim((string) $this->input('observacion'))
                : null,
        ]);
    }
}
