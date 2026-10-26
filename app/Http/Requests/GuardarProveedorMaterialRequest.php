<?php

namespace App\Http\Requests;

use App\Models\ItemMaterial;
use App\Models\ProveedorMaterial;
use App\Services\Materiales\NombreClasificacionMaterial;
use App\Services\Materiales\ServicioCorrelativoProveedorMaterial;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GuardarProveedorMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administrar-catalogos-materiales') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $proveedor = $this->route('proveedorMaterial');
        $proveedorId = $proveedor instanceof ProveedorMaterial ? $proveedor->id : null;

        return [
            'codigo' => [
                'nullable',
                'string',
                'max:80',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('proveedores_materiales', 'codigo')->ignore($proveedorId),
                function (string $atributo, mixed $valor, Closure $fallar) use ($proveedor): void {
                    if ($valor !== $proveedor?->codigo
                        && app(ServicioCorrelativoProveedorMaterial::class)->enUso($valor, $proveedor?->id)) {
                        $fallar('El código ya identifica a otro proveedor, incluso omitiendo guiones, puntos o guiones bajos.');
                    }
                },
            ],
            'codigo_interno' => ['prohibited'],
            'nombre' => ['required', 'string', 'min:2', 'max:180'],
            'codigo_externo' => [
                'nullable',
                'string',
                'max:150',
                Rule::unique('proveedores_materiales', 'codigo_externo')->ignore($proveedorId),
            ],
            'activo' => ['required', 'boolean'],
            'cliente_ids' => ['required', 'array', 'min:1'],
            'cliente_ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('clientes', 'id')->where('activo', true),
            ],
            'categorias' => ['required', 'array', 'min:1'],
            'categorias.*.cliente_id' => ['required', 'uuid'],
            'categorias.*.categoria' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $entradaClienteIds = $this->input('cliente_ids', []);
            $clienteIds = collect(is_array($entradaClienteIds) ? $entradaClienteIds : [])
                ->filter(fn ($clienteId): bool => is_string($clienteId) && $clienteId !== '')
                ->values();
            $categorias = collect($this->input('categorias', []));
            $claves = [];
            $categoriasDisponibles = ItemMaterial::query()
                ->with('cliente:id,cliente_id')
                ->where('activo', true)
                ->whereNotNull('categoria')
                ->whereHas('cliente', fn ($consulta) => $consulta
                    ->whereIn('cliente_id', $clienteIds)
                    ->where('activo', true))
                ->get(['id', 'cliente_material_id', 'categoria'])
                ->groupBy(fn (ItemMaterial $item): string => (string) $item->cliente?->cliente_id)
                ->map(fn ($items) => $items
                    ->map(fn (ItemMaterial $item): string => $this->normalizarCategoria($item->categoria))
                    ->filter()
                    ->unique()
                    ->values());

            foreach ($categorias as $indice => $asignacion) {
                $clienteId = (string) ($asignacion['cliente_id'] ?? '');
                $categoria = trim((string) ($asignacion['categoria'] ?? ''));
                $categoriaNormalizada = $this->normalizarCategoria($categoria);
                $clave = $clienteId.'|'.$categoriaNormalizada;

                if (! $clienteIds->contains($clienteId)) {
                    $validator->errors()->add("categorias.$indice.cliente_id", 'La categoría pertenece a un cliente que no está asociado al proveedor.');
                }
                if (isset($claves[$clave])) {
                    $validator->errors()->add("categorias.$indice.categoria", 'La categoría está repetida para el mismo cliente.');
                }
                $claves[$clave] = true;

                $existe = $clienteId !== ''
                    && $categoriaNormalizada !== ''
                    && $categoriasDisponibles
                        ->get($clienteId, collect())
                        ->contains($categoriaNormalizada);

                if (! $existe) {
                    $validator->errors()->add("categorias.$indice.categoria", 'La categoría no posee ítems activos para el cliente.');
                }
            }

            foreach ($clienteIds as $clienteId) {
                if (! $categorias->contains(fn ($asignacion): bool => ($asignacion['cliente_id'] ?? null) === $clienteId)) {
                    $validator->errors()->add('categorias', 'Cada cliente asociado debe tener al menos una categoría habilitada.');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'codigo_interno.prohibited' => 'El código interno se asigna automáticamente al guardar.',
            'categorias.required' => 'Selecciona al menos una categoría habilitada.',
            'categorias.min' => 'Selecciona al menos una categoría habilitada.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $entradaCategorias = $this->input('categorias', []);
        $categorias = collect(is_array($entradaCategorias) ? $entradaCategorias : [])
            ->map(function ($asignacion): array {
                $asignacion = is_array($asignacion) ? $asignacion : [];

                return [
                    'cliente_id' => (string) ($asignacion['cliente_id'] ?? ''),
                    'categoria' => NombreClasificacionMaterial::mayusculas((string) ($asignacion['categoria'] ?? '')),
                ];
            })
            ->values()
            ->all();

        $this->merge([
            'codigo' => $this->filled('codigo')
                ? mb_strtoupper(trim((string) $this->input('codigo')))
                : ($this->has('codigo') ? null : $this->route('proveedorMaterial')?->codigo),
            'nombre' => trim((string) $this->input('nombre')),
            'codigo_externo' => $this->filled('codigo_externo')
                ? trim((string) $this->input('codigo_externo'))
                : ($this->has('codigo_externo') ? null : $this->route('proveedorMaterial')?->codigo_externo),
            'activo' => $this->boolean('activo'),
            'categorias' => $categorias,
        ]);
    }

    private function normalizarCategoria(mixed $categoria): string
    {
        return mb_strtolower(trim((string) $categoria));
    }
}
