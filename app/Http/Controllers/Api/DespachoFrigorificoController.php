<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoCarga;
use App\Enums\EstadoIncidenciaCarga;
use App\Enums\TipoIncidenciaCarga;
use App\Enums\TipoResolucionIncidenciaCarga;
use App\Http\Controllers\Controller;
use App\Http\Requests\CerrarDespachoFrigorificoRequest;
use App\Http\Requests\EnviarFolioAndenRequest;
use App\Http\Requests\ReportarIncidenciaCargaRequest;
use App\Http\Requests\ResolverIncidenciaCargaRequest;
use App\Http\Resources\CargaResource;
use App\Http\Resources\IncidenciaCargaFolioResource;
use App\Http\Resources\TareaCargaResource;
use App\Models\Anden;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\IncidenciaCargaFolio;
use App\Models\SesionEstiba;
use App\Models\TareaMovimiento;
use App\Services\Autenticacion\ContextoOperacional;
use App\Services\Cargas\PlanificadorExtraccionCarga;
use App\Services\Cargas\RevisionCargaOperacional;
use App\Services\Cargas\ServicioDespachoFrigorifico;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class DespachoFrigorificoController extends Controller
{
    public function incidencias(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('consultar-catalogo-cargas');

        $filtros = $request->validate([
            'estado' => ['nullable', Rule::enum(EstadoIncidenciaCarga::class)],
            'carga_id' => ['nullable', 'uuid', Rule::exists('cargas', 'id')],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $incidencias = IncidenciaCargaFolio::query()
            ->whereHas('asignacion.carga.temporada', fn (Builder $consulta): Builder => $consulta
                ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->when(
                isset($filtros['estado']),
                fn (Builder $consulta): Builder => $consulta
                    ->where('estado', $filtros['estado']),
            )
            ->when(
                isset($filtros['carga_id']),
                fn (Builder $consulta): Builder => $consulta->whereHas(
                    'asignacion',
                    fn (Builder $asignacion): Builder => $asignacion
                        ->where('carga_id', $filtros['carga_id']),
                ),
            )
            ->with($this->relacionesIncidencia())
            ->orderByRaw("CASE estado WHEN 'abierta' THEN 1 ELSE 2 END")
            ->orderByDesc('reportada_at')
            ->paginate((int) ($filtros['per_page'] ?? 25))
            ->withQueryString();

        return IncidenciaCargaFolioResource::collection($incidencias);
    }

    public function tareas(Request $request, Carga $carga): AnonymousResourceCollection
    {
        Gate::authorize('consultar-cargas-operacion');

        return TareaCargaResource::collection(
            $carga->tareas()
                ->with(['camaraOrigen:id,codigo,nombre', 'responsable:id,name'])
                ->orderBy('created_at')
                ->get(),
        );
    }

    public function planExtraccion(
        Request $request,
        Carga $carga,
        PlanificadorExtraccionCarga $planificador,
        RevisionCargaOperacional $revision,
    ): Response {
        Gate::authorize('consultar-cargas-operacion');

        abort_unless(
            in_array($carga->estado, EstadoCarga::visiblesEnOperacion(), true),
            404,
        );

        $etag = 'plan-extraccion-'.$revision->calcular(new Collection([$carga]));
        $respuestaCondicional = $this->configurarCacheOperacion(response('', 200), $etag);

        if ($respuestaCondicional->isNotModified($request)) {
            return $respuestaCondicional;
        }

        return $this->configurarCacheOperacion(
            response()->json(['data' => $planificador->planificar($carga)]),
            $etag,
        );
    }

    private function configurarCacheOperacion(Response $respuesta, string $etag): Response
    {
        $respuesta->setEtag($etag);
        $respuesta->setPrivate();
        $respuesta->headers->addCacheControlDirective('no-cache');
        $respuesta->setVary('Authorization');
        $respuesta->headers->set('Access-Control-Expose-Headers', 'ETag');

        return $respuesta;
    }

    public function reportarIncidencia(
        ReportarIncidenciaCargaRequest $request,
        CargaFolio $cargaFolio,
        ContextoOperacional $contexto,
        ServicioDespachoFrigorifico $servicio,
    ): IncidenciaCargaFolioResource {
        $datos = $request->validated();
        [$usuario, $dispositivo] = $contexto->obtener($request);
        $incidencia = $servicio->reportarIncidencia(
            operacionId: $datos['operacion_id'],
            asignacion: $cargaFolio,
            tipo: TipoIncidenciaCarga::from($datos['tipo']),
            descripcion: $datos['descripcion'] ?? null,
            sesion: SesionEstiba::query()->findOrFail($datos['sesion_estiba_id']),
            usuario: $usuario,
            dispositivo: $dispositivo,
        );

        return new IncidenciaCargaFolioResource(
            $incidencia->load($this->relacionesIncidencia()),
        );
    }

    public function resolverIncidencia(
        ResolverIncidenciaCargaRequest $request,
        IncidenciaCargaFolio $incidencia,
        ServicioDespachoFrigorifico $servicio,
    ): IncidenciaCargaFolioResource {
        $datos = $request->validated();
        $resuelta = $servicio->resolverIncidencia(
            operacionId: $datos['operacion_id'],
            incidencia: $incidencia,
            resolucion: TipoResolucionIncidenciaCarga::from($datos['resolucion']),
            usuario: $request->user(),
            folioReemplazo: isset($datos['folio_reemplazo_id'])
                ? Folio::query()->findOrFail($datos['folio_reemplazo_id'])
                : null,
            observacion: $datos['observacion'] ?? null,
        );

        return new IncidenciaCargaFolioResource(
            $resuelta->load($this->relacionesIncidencia()),
        );
    }

    public function enviarAnden(
        EnviarFolioAndenRequest $request,
        CargaFolio $cargaFolio,
        ContextoOperacional $contexto,
        ServicioDespachoFrigorifico $servicio,
    ): CargaResource {
        $datos = $request->validated();
        [$usuario, $dispositivo] = $contexto->obtener($request);
        $asignacion = $servicio->enviarFolioAnden(
            operacionId: $datos['operacion_id'],
            asignacion: $cargaFolio,
            anden: Anden::query()->findOrFail($datos['anden_id']),
            sesion: SesionEstiba::query()->findOrFail($datos['sesion_estiba_id']),
            usuario: $usuario,
            dispositivo: $dispositivo,
            versionCamaraConocida: $datos['version_camara_conocida'],
            generadoDispositivoAt: CarbonImmutable::parse($datos['generado_dispositivo_at']),
            advertenciasConfirmadas: $datos['advertencias_confirmadas'] ?? [],
            tareaMovimiento: isset($datos['tarea_movimiento_id'])
                ? TareaMovimiento::query()->findOrFail($datos['tarea_movimiento_id'])
                : null,
        );

        return new CargaResource($this->cargarCarga($asignacion->carga));
    }

    public function cerrar(
        CerrarDespachoFrigorificoRequest $request,
        Carga $carga,
        ServicioDespachoFrigorifico $servicio,
    ): CargaResource {
        $datos = $request->validated();
        $cerrada = $servicio->cerrarDespacho(
            operacionId: $datos['operacion_id'],
            carga: $carga,
            usuario: $request->user(),
            patente: $datos['patente'],
            conductor: $datos['conductor'],
            observacion: $datos['observacion'] ?? null,
            ocurridoAt: isset($datos['ocurrido_at'])
                ? CarbonImmutable::parse($datos['ocurrido_at'])
                : null,
        );

        return new CargaResource($this->cargarCarga($cerrada));
    }

    private function cargarCarga(Carga $carga): Carga
    {
        return $carga
            ->load([
                'camaraObjetivo:id,codigo,nombre',
                'andenPrevisto:id,codigo,nombre',
                'presenciaAndenActiva.anden:id,codigo,nombre',
                'presenciaAndenActiva.ingresadaPor:id,name',
                'creadaPor:id,name',
                'actualizadaPor:id,name',
                'publicadaPor:id,name',
                'canceladaPor:id,name',
                'cerradaPor:id,name',
                'asignacionesActuales.asignadoPor:id,name',
                'asignacionesActuales.anden:id,codigo,nombre',
                'asignacionesActuales.folio.ubicacionActual.posicion.camara:id,codigo,nombre',
                'asignacionesHistoricas.anden:id,codigo,nombre',
                'asignacionesHistoricas.folio.ubicacionActual.posicion.camara:id,codigo,nombre',
                'tareas.camaraOrigen:id,codigo,nombre',
                'tareas.responsable:id,name',
            ])
            ->loadCount([
                'incidencias as incidencias_abiertas' => fn (Builder $consulta): Builder => $consulta
                    ->where('incidencias_carga_folio.estado', EstadoIncidenciaCarga::Abierta->value),
            ]);
    }

    /** @return array<int, string> */
    private function relacionesIncidencia(): array
    {
        return [
            'asignacion.carga:id,codigo,numero_orden_externa,prioridad,estado',
            'asignacion.folio:id,numero_folio,tipo_bulto,variedad,calibre,marca,exportadora',
            'camara:id,codigo,nombre',
            'posicion:id,camara_id,banda,posicion,nivel,etiqueta',
            'reportadoPor:id,name',
            'dispositivo:id,codigo,nombre',
            'resueltaPor:id,name',
            'asignacionReemplazo.folio:id,numero_folio',
        ];
    }
}
