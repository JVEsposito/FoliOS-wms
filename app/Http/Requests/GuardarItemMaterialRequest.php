<?php

namespace App\Http\Requests;

use App\Enums\CategoriaOperacionalMaterial;
use App\Models\ItemMaterial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarItemMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administrar-catalogos-materiales') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $item = $this->route('itemMaterial');
        $itemId = $item instanceof ItemMaterial ? $item->id : null;
        $clienteId = (string) $this->input('cliente_material_id');

        return [
            'cliente_material_id' => ['required', 'uuid', 'exists:clientes_materiales,id'],
            'codigo' => [
                'required',
                'string',
                'max:80',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('items_materiales', 'codigo')
                    ->where(fn ($consulta) => $consulta->where('cliente_material_id', $clienteId))
                    ->ignore($itemId),
            ],
            'nombre' => ['required', 'string', 'min:3', 'max:180'],
            'categoria' => ['nullable', 'string', 'max:100'],
            'categoria_operacional' => [
                'required',
                Rule::enum(CategoriaOperacionalMaterial::class),
            ],
            'unidad_medida' => ['required', 'string', 'max:40'],
            'codigo_externo' => [
                'nullable',
                'string',
                'max:150',
                Rule::unique('items_materiales', 'codigo_externo')
                    ->where(fn ($consulta) => $consulta->where('cliente_material_id', $clienteId))
                    ->ignore($itemId),
            ],
            'activo' => ['sometimes', 'boolean'],
            'dias_alerta_vencimiento' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'stock_minimo' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999999.999', 'decimal:0,3'],
            'punto_reorden' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999999.999', 'decimal:0,3'],
            'stock_maximo' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999999.999', 'decimal:0,3'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'categoria_operacional.required' => 'Selecciona el tipo de ítem.',
            'categoria_operacional.enum' => 'El tipo de ítem debe ser Insumo, Material MP o Material PT.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'codigo' => mb_strtoupper(trim((string) $this->input('codigo'))),
            'nombre' => trim((string) $this->input('nombre')),
            'categoria' => $this->filled('categoria') ? trim((string) $this->input('categoria')) : null,
            'categoria_operacional' => $this->filled('categoria_operacional')
                ? mb_strtolower(trim((string) $this->input('categoria_operacional')))
                : null,
            'unidad_medida' => mb_strtolower(trim((string) $this->input('unidad_medida'))),
            'codigo_externo' => $this->filled('codigo_externo')
                ? trim((string) $this->input('codigo_externo'))
                : null,
        ]);
    }
}
