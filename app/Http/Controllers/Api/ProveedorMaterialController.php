<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarProveedorMaterialRequest;
use App\Models\ClienteProveedorMaterial;
use App\Models\ProveedorMaterial;
use App\Services\Materiales\ServicioCorrelativoProveedorMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ProveedorMaterialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('administrar-catalogos-materiales');

        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'cliente_id' => ['nullable', 'uuid', Rule::exists('clientes', 'id')],
            'estado' => ['nullable', Rule::in(['todos', 'activo', 'inactivo'])],
        ]);

        return response()->json([
            'data' => ProveedorMaterial::query()
                ->with([
                    'clientes' => fn ($consulta) => $consulta
                        ->wherePivot('activo', true)
                        ->orderBy('clientes.codigo'),
                ])
                ->when($request->filled('q'), function ($consulta) use ($filtros): void {
                    $texto = $filtros['q'];
                    $consulta->where(function ($consulta) use ($texto): void {
                        $patron = '%'.trim($texto).'%';
                        $consulta->whereLike('nombre', $patron)
                            ->orWhereLike('codigo', $patron)
                            ->orWhereLike('codigo_interno', $patron)
                            ->orWhereLike('codigo_externo', $patron)
                            ->orWhereRaw("REPLACE(REPLACE(REPLACE(UPPER(codigo), '-', ''), '.', ''), '_', '') LIKE ?", ['%'.ServicioCorrelativoProveedorMaterial::normalizar($texto).'%'])
                            ->orWhereRaw("REPLACE(UPPER(codigo_interno), '-', '') LIKE ?", ['%'.ServicioCorrelativoProveedorMaterial::normalizar($texto).'%'])
                            ->orWhereHas('clientes', fn ($clientes) => $clientes->where('clientes_proveedores_materiales.activo', true)
                                ->where(fn ($cliente) => $cliente->whereLike('clientes.nombre', $patron)->orWhereLike('clientes.codigo', $patron)));
                    });
                })
                ->when($filtros['cliente_id'] ?? null, fn ($consulta, string $clienteId) => $consulta
                    ->whereHas('clientes', fn ($clientes) => $clientes->where('clientes.id', $clienteId)->where('clientes_proveedores_materiales.activo', true)))
                ->when(in_array($filtros['estado'] ?? 'todos', ['activo', 'inactivo'], true), fn ($consulta) => $consulta
                    ->where('activo', $filtros['estado'] === 'activo'))
                ->orderByDesc('activo')
                ->orderBy('codigo')
                ->get()
                ->map(fn (ProveedorMaterial $proveedor): array => $this->proveedor($proveedor)),
        ]);
    }

    public function store(GuardarProveedorMaterialRequest $request): JsonResponse
    {
        $proveedor = $this->guardar($request);

        return response()->json(
            ['data' => $this->proveedor($proveedor)],
            Response::HTTP_CREATED,
        );
    }

    public function update(
        GuardarProveedorMaterialRequest $request,
        ProveedorMaterial $proveedorMaterial,
    ): JsonResponse {
        return response()->json([
            'data' => $this->proveedor($this->guardar($request, $proveedorMaterial)),
        ]);
    }

    private function guardar(
        GuardarProveedorMaterialRequest $request,
        ?ProveedorMaterial $proveedor = null,
    ): ProveedorMaterial {
        $datos = $request->validated();

        return DB::transaction(function () use ($datos, $request, $proveedor): ProveedorMaterial {
            $correlativo = app(ServicioCorrelativoProveedorMaterial::class);
            $correlativo->bloquear();
            $correlativo->validarCodigoComercial($datos['codigo'], $proveedor);
            $proveedor ??= new ProveedorMaterial;
            $proveedor->fill([
                'codigo' => $datos['codigo'] ?? $proveedor->codigo_interno,
                'nombre' => $datos['nombre'],
                'codigo_externo' => $datos['codigo_externo'],
                'activo' => $datos['activo'],
                'creado_por_user_id' => $proveedor->creado_por_user_id ?? $request->user()->id,
                'actualizado_por_user_id' => $request->user()->id,
            ]);
            $proveedor->save();

            ClienteProveedorMaterial::query()
                ->where('proveedor_material_id', $proveedor->id)
                ->update([
                    'activo' => false,
                    'actualizado_por_user_id' => $request->user()->id,
                    'updated_at' => now(),
                ]);

            $categoriasPorCliente = collect($datos['categorias'])
                ->groupBy('cliente_id')
                ->map(fn ($asignaciones) => $asignaciones
                    ->pluck('categoria')
                    ->unique(fn (string $categoria): string => mb_strtolower($categoria))
                    ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all());

            foreach ($datos['cliente_ids'] as $clienteId) {
                ClienteProveedorMaterial::query()->updateOrCreate(
                    [
                        'cliente_id' => $clienteId,
                        'proveedor_material_id' => $proveedor->id,
                    ],
                    [
                        'activo' => true,
                        'categorias' => $categoriasPorCliente->get($clienteId, []),
                        'creado_por_user_id' => $request->user()->id,
                        'actualizado_por_user_id' => $request->user()->id,
                    ],
                );
            }

            return $proveedor->refresh()->load([
                'clientes' => fn ($consulta) => $consulta
                    ->wherePivot('activo', true)
                    ->orderBy('clientes.codigo'),
            ]);
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function proveedor(ProveedorMaterial $proveedor): array
    {
        return [
            'id' => $proveedor->id,
            'codigo_interno' => $proveedor->codigo_interno,
            'codigo' => $proveedor->codigo,
            'nombre' => $proveedor->nombre,
            'codigo_externo' => $proveedor->codigo_externo,
            'activo' => $proveedor->activo,
            'clientes' => $proveedor->clientes->map(fn ($cliente): array => [
                'id' => $cliente->id,
                'codigo' => $cliente->codigo,
                'nombre' => $cliente->nombre,
                'categorias' => $this->categoriasPivot($cliente->pivot?->categorias),
            ])->values(),
            'categorias' => $proveedor->clientes->flatMap(fn ($cliente) => collect(
                $this->categoriasPivot($cliente->pivot?->categorias),
            )->map(fn (string $categoria): array => [
                'cliente_id' => $cliente->id,
                'categoria' => $categoria,
            ]))->values(),
            'created_at' => $proveedor->created_at?->toAtomString(),
            'updated_at' => $proveedor->updated_at?->toAtomString(),
        ];
    }

    /** @return list<string> */
    private function categoriasPivot(mixed $valor): array
    {
        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }

        return collect(is_array($valor) ? $valor : [])
            ->map(fn ($categoria): string => trim((string) $categoria))
            ->filter()
            ->unique(fn (string $categoria): string => mb_strtolower($categoria))
            ->values()
            ->all();
    }
}
