<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarDestinoMaterialRequest;
use App\Http\Requests\GuardarItemMaterialRequest;
use App\Http\Requests\RegularizarItemMaterialRequest;
use App\Http\Resources\ClienteMaterialResource;
use App\Http\Resources\DestinoMaterialResource;
use App\Http\Resources\ItemMaterialResource;
use App\Http\Resources\TemporadaMaterialResource;
use App\Models\ClienteMaterial;
use App\Models\DestinoMaterial;
use App\Models\ItemMaterial;
use App\Models\TemporadaMaterial;
use App\Services\Materiales\ServicioRegularizacionItemMaterial;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CatalogoMaterialController extends Controller
{
    public function catalogo(): JsonResponse
    {
        Gate::authorize('consultar-despachos-materiales');
        $temporada = TemporadaMaterial::query()->where('activa', true)->first();

        if ($temporada === null) {
            return response()->json([
                'temporada' => null,
                'clientes' => [],
                'items' => [],
                'destinos' => DestinoMaterialResource::collection(
                    DestinoMaterial::query()->where('activo', true)->orderBy('nombre')->get(),
                ),
            ]);
        }

        return response()->json([
            'temporada' => new TemporadaMaterialResource($temporada),
            'clientes' => ClienteMaterialResource::collection(
                ClienteMaterial::query()
                    ->with('temporada')
                    ->where('temporada_material_id', $temporada->id)
                    ->where('activo', true)
                    ->withCount(['items as items_activos_count' => fn ($consulta) => $consulta->where('activo', true)])
                    ->orderBy('nombre')
                    ->get(),
            ),
            'items' => ItemMaterialResource::collection(
                ItemMaterial::query()
                    ->with(['cliente.temporada', 'fotoPrincipal'])->withCount('fotos')
                    ->where('activo', true)
                    ->whereHas('cliente', fn ($consulta) => $consulta
                        ->where('temporada_material_id', $temporada->id)
                        ->where('activo', true))
                    ->orderBy(ClienteMaterial::query()
                        ->select('nombre')
                        ->whereColumn('clientes_materiales.id', 'items_materiales.cliente_material_id'))
                    ->orderBy('nombre')
                    ->get(),
            ),
            'destinos' => DestinoMaterialResource::collection(
                DestinoMaterial::query()->where('activo', true)->orderBy('nombre')->get(),
            ),
        ]);
    }

    public function items(): JsonResponse
    {
        Gate::authorize('administrar-catalogos-materiales');

        $items = ItemMaterial::query()
            ->with(['cliente.temporada', 'regularizacionComoDuplicado.itemCanonico', 'fotoPrincipal'])->withCount('fotos')
            ->withCount([
                'foliosMateriales as folios_activos_count' => fn ($consulta) => $consulta
                    ->whereHas('folio', fn ($folios) => $folios->where('activo', true)),
            ])
            ->orderByDesc(TemporadaMaterial::query()
                ->select('activa')
                ->join('clientes_materiales', 'clientes_materiales.temporada_material_id', '=', 'temporadas_materiales.id')
                ->whereColumn('clientes_materiales.id', 'items_materiales.cliente_material_id'))
            ->orderByDesc('activo')
            ->orderBy(ClienteMaterial::query()
                ->select('nombre')
                ->whereColumn('clientes_materiales.id', 'items_materiales.cliente_material_id'))
            ->orderBy('nombre')
            ->get();

        return response()->json(['data' => ItemMaterialResource::collection($items)]);
    }

    public function clientes(): JsonResponse
    {
        Gate::authorize('administrar-catalogos-materiales');

        $clientes = ClienteMaterial::query()
            ->with('temporada')
            ->withCount(['items as items_activos_count' => fn ($consulta) => $consulta->where('activo', true)])
            ->orderByDesc(TemporadaMaterial::query()
                ->select('activa')
                ->whereColumn('temporadas_materiales.id', 'clientes_materiales.temporada_material_id'))
            ->orderByDesc('activo')
            ->orderBy('nombre')
            ->get();

        return response()->json(['data' => ClienteMaterialResource::collection($clientes)]);
    }

    public function temporadas(): JsonResponse
    {
        Gate::authorize('administrar-catalogos-materiales');

        $temporadas = TemporadaMaterial::query()
            ->withCount([
                'clientes as clientes_activos_count' => fn ($consulta) => $consulta->where('activo', true),
                'items as items_activos_count' => fn ($consulta) => $consulta->where('items_materiales.activo', true),
            ])
            ->orderByDesc('activa')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => TemporadaMaterialResource::collection($temporadas)]);
    }

    public function storeItem(GuardarItemMaterialRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $this->validarClienteActivo($request->validated('cliente_material_id'));
            $item = ItemMaterial::create([
                ...$request->validated(),
                'activo' => true,
                'origen_sistema' => 'manual',
                'creado_por_user_id' => $request->user()->id,
                'actualizado_por_user_id' => $request->user()->id,
            ]);

            return (new ItemMaterialResource($item->load('cliente.temporada')))
                ->response()
                ->setStatusCode(Response::HTTP_CREATED);
        }, 3);
    }

    public function updateItem(
        GuardarItemMaterialRequest $request,
        ItemMaterial $itemMaterial,
    ): ItemMaterialResource {
        return DB::transaction(function () use ($request, $itemMaterial) {
            $itemMaterial = ItemMaterial::whereKey($itemMaterial->id)->lockForUpdate()->firstOrFail();
            $datos = $request->validated();
            $this->validarClienteActivo($datos['cliente_material_id']);

            if ($itemMaterial->regularizacionComoDuplicado()->exists()) {
                throw new DomainException('Un ítem ya consolidado conserva sus datos históricos y no puede editarse ni reactivarse.');
            }
            if (($datos['activo'] ?? true) === false
                && $itemMaterial->regularizacionesComoCanonico()->exists()) {
                throw new DomainException('El ítem es canónico para uno o más códigos consolidados y no puede desactivarse.');
            }

            if ($itemMaterial->foliosMateriales()->exists()) {
                unset($datos['unidad_medida']);
                if ($itemMaterial->cliente_material_id !== $datos['cliente_material_id']
                    && $itemMaterial->cliente()->value('codigo') !== 'GENERAL') {
                    abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'El cliente no puede cambiar porque el ítem ya posee folios asociados.');
                }
            }

            $itemMaterial->update([
                ...$datos,
                'actualizado_por_user_id' => $request->user()->id,
            ]);

            return new ItemMaterialResource($itemMaterial->refresh()->load('cliente.temporada'));
        }, 3);
    }

    public function regularizarItem(
        RegularizarItemMaterialRequest $request,
        ItemMaterial $itemMaterial,
        ServicioRegularizacionItemMaterial $servicio,
    ): JsonResponse {
        $regularizacion = $servicio->regularizar(
            $itemMaterial,
            $request->validated('item_canonico_id'),
            $request->validated('operacion_id'),
            $request->validated('motivo'),
            $request->user(),
        );

        return response()->json(['data' => [
            'id' => $regularizacion->id,
            'item_duplicado' => [
                'id' => $regularizacion->itemDuplicado->id,
                'codigo' => $regularizacion->itemDuplicado->codigo,
                'nombre' => $regularizacion->itemDuplicado->nombre,
            ],
            'item_canonico' => [
                'id' => $regularizacion->itemCanonico->id,
                'codigo' => $regularizacion->itemCanonico->codigo,
                'nombre' => $regularizacion->itemCanonico->nombre,
            ],
            'recetas_versionadas' => $regularizacion->snapshot['recetas_versionadas'] ?? [],
            'motivo' => $regularizacion->motivo,
            'usuario' => [
                'id' => $regularizacion->usuario->id,
                'nombre' => $regularizacion->usuario->name,
            ],
            'ocurrido_at' => $regularizacion->ocurrido_at?->toAtomString(),
        ]]);
    }

    public function destinos(): JsonResponse
    {
        Gate::authorize('administrar-catalogos-materiales');

        return response()->json([
            'data' => DestinoMaterialResource::collection(
                DestinoMaterial::query()->orderByDesc('activo')->orderBy('nombre')->get(),
            ),
        ]);
    }

    public function storeDestino(GuardarDestinoMaterialRequest $request): JsonResponse
    {
        $this->validarDestinoUnico($request);
        $destino = DestinoMaterial::create([
            ...$request->validated(),
            'activo' => true,
            'origen_sistema' => 'manual',
            'creado_por_user_id' => $request->user()->id,
            'actualizado_por_user_id' => $request->user()->id,
        ]);

        return (new DestinoMaterialResource($destino))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateDestino(
        GuardarDestinoMaterialRequest $request,
        DestinoMaterial $destinoMaterial,
    ): DestinoMaterialResource {
        $this->validarDestinoUnico($request, $destinoMaterial);
        $destinoMaterial->update([
            ...$request->validated(),
            'actualizado_por_user_id' => $request->user()->id,
        ]);

        return new DestinoMaterialResource($destinoMaterial->refresh());
    }

    private function validarDestinoUnico(
        Request $request,
        ?DestinoMaterial $destino = null,
    ): void {
        $duplicado = DestinoMaterial::query()
            ->where('nombre', $request->string('nombre')->trim()->toString())
            ->where('centro_costo', $request->string('centro_costo')->trim()->upper()->toString())
            ->when($destino, fn ($consulta) => $consulta->where('id', '!=', $destino->id))
            ->exists();

        abort_if($duplicado, Response::HTTP_UNPROCESSABLE_ENTITY, 'El destino ya existe para ese centro de costo.');
    }

    private function validarClienteActivo(string $clienteId): void
    {
        abort_unless(
            ClienteMaterial::query()->whereKey($clienteId)->where('activo', true)->exists(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'El cliente de materiales no existe o se encuentra inactivo.',
        );
    }
}
