<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AbrirLoteTransformacionMaterialRequest;
use App\Http\Requests\CancelarOrdenTransformacionMaterialRequest;
use App\Http\Requests\CerrarLoteTransformacionMaterialRequest;
use App\Http\Requests\CerrarOrdenTransformacionMaterialRequest;
use App\Http\Requests\CrearOrdenTransformacionMaterialRequest;
use App\Http\Requests\CrearRecetaMaterialRequest;
use App\Http\Requests\CrearVersionRecetaMaterialRequest;
use App\Http\Requests\IniciarOrdenTransformacionMaterialRequest;
use App\Http\Requests\PlanificarOrdenTransformacionMaterialRequest;
use App\Http\Requests\RevertirLoteTransformacionMaterialRequest;
use App\Http\Resources\OrdenTransformacionMaterialResource;
use App\Http\Resources\RecetaMaterialResource;
use App\Models\Dispositivo;
use App\Models\LoteTransformacionMaterial;
use App\Models\OrdenTransformacionMaterial;
use App\Models\PersonalAccessToken;
use App\Models\RecetaMaterial;
use App\Services\Materiales\ServicioTransformacionMaterial;
use App\Services\Materiales\ServicioVersionRecetaMaterial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class TransformacionMaterialController extends Controller
{
    public function recetas(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('consultar-transformaciones-materiales');
        $recetas = RecetaMaterial::query()
            ->where('temporada_id', '=', $this->consultaTemporadaActiva())
            ->withCount('versiones')
            ->with([
                'temporada:id,codigo,nombre,activa',
                'cliente:id,codigo,nombre,codigo_folio_materiales,activo',
                'itemSalida',
                'versiones' => fn ($consulta) => $consulta
                    ->with('detalles.itemEntrada')
                    ->orderByDesc('numero_version')
                    ->limit(1),
                'creadoPor:id,name',
                'actualizadoPor:id,name',
            ])
            ->when($request->query('cliente_id'), fn ($consulta, $cliente) => $consulta
                ->where('cliente_id', $cliente))
            ->when($request->query('activa') !== null, fn ($consulta) => $consulta
                ->where('activa', $request->boolean('activa')))
            ->latest()
            ->paginate(min(100, max(10, (int) $request->query('per_page', 25))));

        return RecetaMaterialResource::collection($recetas);
    }

    public function guardarReceta(
        CrearRecetaMaterialRequest $request,
        ServicioTransformacionMaterial $servicio,
    ): JsonResponse {
        return (new RecetaMaterialResource(
            $servicio->crearReceta($request->validated(), $request->user()),
        ))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function guardarVersionReceta(
        CrearVersionRecetaMaterialRequest $request,
        RecetaMaterial $recetaMaterial,
        ServicioVersionRecetaMaterial $servicio,
    ): JsonResponse {
        return (new RecetaMaterialResource(
            $servicio->crear($recetaMaterial, $request->validated(), $request->user()),
        ))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function ordenes(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('consultar-transformaciones-materiales');
        $ordenes = OrdenTransformacionMaterial::query()
            ->where('temporada_id', '=', $this->consultaTemporadaActiva())
            ->withCount(['reservas', 'lotes'])
            ->withExists([
                'lotes as tiene_salidas' => fn ($consulta) => $consulta
                    ->where('estado', 'cerrado')
                    ->whereHas('salidas'),
            ])
            ->with([
                'temporada:id,codigo,nombre,activa',
                'cliente:id,codigo,nombre,codigo_folio_materiales,activo',
                'versionReceta.receta.itemSalida',
                'creadoPor:id,name',
            ])
            ->when($request->query('estado'), fn ($consulta, $estado) => $consulta
                ->where('estado', $estado))
            ->when($request->query('cliente_id'), fn ($consulta, $cliente) => $consulta
                ->where('cliente_id', $cliente))
            ->latest()
            ->paginate(min(100, max(10, (int) $request->query('per_page', 25))));

        return OrdenTransformacionMaterialResource::collection($ordenes);
    }

    public function mostrarOrden(
        OrdenTransformacionMaterial $ordenTransformacionMaterial,
        ServicioTransformacionMaterial $servicio,
    ): OrdenTransformacionMaterialResource {
        Gate::authorize('consultar-transformaciones-materiales');

        return new OrdenTransformacionMaterialResource(
            $servicio->cargarOrden($ordenTransformacionMaterial),
        );
    }

    public function guardarOrden(
        CrearOrdenTransformacionMaterialRequest $request,
        ServicioTransformacionMaterial $servicio,
    ): JsonResponse {
        return (new OrdenTransformacionMaterialResource(
            $servicio->crearOrden($request->validated(), $request->user()),
        ))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function planificar(
        PlanificarOrdenTransformacionMaterialRequest $request,
        OrdenTransformacionMaterial $ordenTransformacionMaterial,
        ServicioTransformacionMaterial $servicio,
    ): OrdenTransformacionMaterialResource {
        return new OrdenTransformacionMaterialResource($servicio->planificar(
            $ordenTransformacionMaterial,
            $request->validated('operacion_id'),
            $request->integer('version_conocida'),
            $request->user(),
        ));
    }

    public function cancelar(
        CancelarOrdenTransformacionMaterialRequest $request,
        OrdenTransformacionMaterial $ordenTransformacionMaterial,
        ServicioTransformacionMaterial $servicio,
    ): OrdenTransformacionMaterialResource {
        return new OrdenTransformacionMaterialResource($servicio->cancelar(
            $ordenTransformacionMaterial,
            $request->validated('operacion_id'),
            $request->validated('motivo'),
            $request->user(),
        ));
    }

    public function iniciar(
        IniciarOrdenTransformacionMaterialRequest $request,
        OrdenTransformacionMaterial $ordenTransformacionMaterial,
        ServicioTransformacionMaterial $servicio,
    ): OrdenTransformacionMaterialResource {
        return new OrdenTransformacionMaterialResource($servicio->iniciar(
            $ordenTransformacionMaterial,
            $request->validated('operacion_id'),
            $request->integer('version_conocida'),
            $request->user(),
            $this->dispositivo($request),
        ));
    }

    public function abrirLote(
        AbrirLoteTransformacionMaterialRequest $request,
        OrdenTransformacionMaterial $ordenTransformacionMaterial,
        ServicioTransformacionMaterial $servicio,
    ): OrdenTransformacionMaterialResource {
        return new OrdenTransformacionMaterialResource($servicio->abrirLote(
            $ordenTransformacionMaterial,
            $request->validated('operacion_id'),
            $request->integer('version_conocida'),
            (float) $request->validated('cantidad_planificada_salida'),
            $request->user(),
            $this->dispositivo($request),
        ));
    }

    public function cerrarLote(
        CerrarLoteTransformacionMaterialRequest $request,
        LoteTransformacionMaterial $loteTransformacionMaterial,
        ServicioTransformacionMaterial $servicio,
    ): OrdenTransformacionMaterialResource {
        return new OrdenTransformacionMaterialResource($servicio->cerrarLote(
            $loteTransformacionMaterial,
            $request->validated(),
            $request->user(),
            $this->dispositivo($request),
        ));
    }

    public function cerrarOrden(
        CerrarOrdenTransformacionMaterialRequest $request,
        OrdenTransformacionMaterial $ordenTransformacionMaterial,
        ServicioTransformacionMaterial $servicio,
    ): OrdenTransformacionMaterialResource {
        return new OrdenTransformacionMaterialResource($servicio->cerrarOrden(
            $ordenTransformacionMaterial,
            $request->validated('operacion_id'),
            $request->integer('version_conocida'),
            $request->validated('motivo_desviacion'),
            $request->user(),
            $this->dispositivo($request),
        ));
    }

    public function revertirLote(
        RevertirLoteTransformacionMaterialRequest $request,
        LoteTransformacionMaterial $loteTransformacionMaterial,
        ServicioTransformacionMaterial $servicio,
    ): OrdenTransformacionMaterialResource {
        return new OrdenTransformacionMaterialResource($servicio->revertirLote(
            $loteTransformacionMaterial,
            $request->validated('operacion_id'),
            $request->integer('version_conocida'),
            $request->validated('motivo'),
            $request->user(),
            $this->dispositivo($request),
        ));
    }

    private function dispositivo(Request $request): ?Dispositivo
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->dispositivo_id === null) {
            return null;
        }

        return Dispositivo::query()
            ->whereKey($token->dispositivo_id)
            ->where('activo', true)
            ->first();
    }

    private function consultaTemporadaActiva(): Builder
    {
        return app(ServicioTemporadaActiva::class)->subconsultaId();
    }
}
