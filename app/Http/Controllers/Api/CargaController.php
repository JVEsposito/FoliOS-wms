<?php

namespace App\Http\Controllers\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoCarga;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoIncidenciaCarga;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPosicion;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\TipoBulto;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActualizarCargaRequest;
use App\Http\Requests\AgregarFoliosCargaRequest;
use App\Http\Requests\CrearCargaRequest;
use App\Http\Requests\FinalizarPresenciaCargaAndenRequest;
use App\Http\Requests\RegistrarDespachoDirectoPrefrioRequest;
use App\Http\Requests\RegistrarPresenciaCargaAndenRequest;
use App\Http\Requests\VersionCargaRequest;
use App\Http\Resources\CargaResource;
use App\Http\Resources\FolioDisponibleCargaResource;
use App\Http\Resources\FolioSalidaDirectaPrefrioResource;
use App\Models\Carga;
use App\Models\Folio;
use App\Services\Cargas\RevisionCargaOperacional;
use App\Services\Cargas\ServicioCarga;
use App\Services\Cargas\ServicioPresenciaCargaAnden;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class CargaController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('consultar-catalogo-cargas');

        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::enum(EstadoCarga::class)],
            'solo_con_incidencias' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $busqueda = trim((string) ($filtros['q'] ?? ''));

        $cargas = Carga::query()
            ->whereHas('temporada', fn (Builder $consulta): Builder => $consulta
                ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->with($this->relacionesDetalle())
            ->withCount([
                'incidencias as incidencias_abiertas' => fn (Builder $consulta): Builder => $consulta
                    ->where('incidencias_carga_folio.estado', EstadoIncidenciaCarga::Abierta->value),
            ])
            ->when(
                $busqueda !== '',
                fn (Builder $consulta): Builder => $consulta->where(
                    fn (Builder $coincidencia): Builder => $coincidencia
                        ->where('codigo', 'like', "%{$busqueda}%")
                        ->orWhere('numero_orden_externa', 'like', "%{$busqueda}%")
                        ->orWhere('observacion', 'like', "%{$busqueda}%")
                        ->orWhereHas(
                            'embarque',
                            fn (Builder $embarque): Builder => $embarque
                                ->where('codigo', 'like', "%{$busqueda}%"),
                        )
                        ->orWhereHas(
                            'embarque.instructivos',
                            fn (Builder $instructivo): Builder => $instructivo
                                ->where('numero_externo', 'like', "%{$busqueda}%"),
                        )
                        ->orWhereHas(
                            'camaraObjetivo',
                            fn (Builder $camara): Builder => $camara
                                ->where('codigo', 'like', "%{$busqueda}%")
                                ->orWhere('nombre', 'like', "%{$busqueda}%"),
                        ),
                ),
            )
            ->when(
                isset($filtros['estado']),
                fn (Builder $consulta): Builder => $consulta
                    ->where('estado', $filtros['estado']),
            )
            ->when(
                $request->boolean('solo_con_incidencias'),
                fn (Builder $consulta): Builder => $consulta->whereHas(
                    'incidencias',
                    fn (Builder $incidencia): Builder => $incidencia
                        ->where('incidencias_carga_folio.estado', EstadoIncidenciaCarga::Abierta->value),
                ),
            )
            ->orderByDesc('created_at')
            ->paginate((int) ($filtros['per_page'] ?? 25))
            ->withQueryString();

        return CargaResource::collection($cargas);
    }

    public function pendientes(
        Request $request,
        RevisionCargaOperacional $revision,
    ): Response {
        Gate::authorize('consultar-cargas-operacion');

        $etag = 'cargas-operacion-'.$revision->calcular();
        $respuestaCondicional = $this->configurarCacheOperacion(response('', 200), $etag);

        if ($respuestaCondicional->isNotModified($request)) {
            return $respuestaCondicional;
        }

        $cargas = Carga::query()
            ->whereHas('temporada', fn (Builder $consulta): Builder => $consulta
                ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->whereIn(
                'estado',
                collect(EstadoCarga::visiblesEnOperacion())
                    ->map(fn (EstadoCarga $estado): string => $estado->value)
                    ->all(),
            )
            ->orderByRaw(
                "CASE prioridad WHEN 'urgente' THEN 1 WHEN 'alta' THEN 2 ELSE 3 END",
            )
            ->orderBy('publicada_at')
            ->get();

        $cargas
            ->load($this->relacionesOperacion())
            ->loadCount([
                'incidencias as incidencias_abiertas' => fn (Builder $consulta): Builder => $consulta
                    ->where('incidencias_carga_folio.estado', EstadoIncidenciaCarga::Abierta->value),
            ]);

        return $this->configurarCacheOperacion(
            CargaResource::collection($cargas)->response(),
            $etag,
        );
    }

    public function foliosSalidaDirectaPrefrio(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('gestionar-cargas');

        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $busqueda = trim((string) ($filtros['q'] ?? ''));

        $folios = Folio::query()
            ->where('activo', true)
            ->whereHas('temporada', fn (Builder $consulta): Builder => $consulta
                ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->whereIn('tipo_bulto', [
                TipoBulto::Pallet->value,
                TipoBulto::Saldo->value,
            ])
            ->whereIn('estado_operacional', [
                EstadoOperacionalFolio::PendientePrefrio->value,
                EstadoOperacionalFolio::PendienteUbicacion->value,
                EstadoOperacionalFolio::Disponible->value,
            ])
            ->where('condicion_termica', CondicionTermicaFolio::PrefrioAprobado->value)
            ->where(
                'habilitacion_almacenamiento',
                HabilitacionAlmacenamientoFolio::Habilitado->value,
            )
            ->whereDoesntHave('ubicacionActual')
            ->whereDoesntHave('asignacionCargaActual')
            ->whereHas('procesosPrefrio', fn (Builder $asignacion): Builder => $asignacion
                ->where('estado', EstadoFolioProcesoPrefrio::Aprobado->value)
                ->whereHas('proceso', fn (Builder $proceso): Builder => $proceso
                    ->where('estado', EstadoProcesoPrefrio::Aprobado->value)
                    ->whereNotNull('finalizado_at')))
            ->when(
                $busqueda !== '',
                fn (Builder $consulta): Builder => $consulta->where(
                    fn (Builder $coincidencia): Builder => $coincidencia
                        ->where('numero_folio', 'like', "%{$busqueda}%")
                        ->orWhere('variedad', 'like', "%{$busqueda}%")
                        ->orWhere('calibre', 'like', "%{$busqueda}%")
                        ->orWhere('marca', 'like', "%{$busqueda}%")
                        ->orWhere('exportadora', 'like', "%{$busqueda}%")
                        ->orWhereHas(
                            'condicionSag',
                            fn (Builder $condicion): Builder => $condicion
                                ->where('codigo', 'like', "%{$busqueda}%")
                                ->orWhere('nombre', 'like', "%{$busqueda}%"),
                        ),
                ),
            )
            ->with([
                'condicionSag:id,codigo,nombre',
                'procesosPrefrio' => fn ($consulta) => $consulta
                    ->where('estado', EstadoFolioProcesoPrefrio::Aprobado->value)
                    ->whereHas('proceso', fn ($proceso) => $proceso
                        ->where('estado', EstadoProcesoPrefrio::Aprobado->value)
                        ->whereNotNull('finalizado_at'))
                    ->with('proceso:id,codigo,estado,finalizado_at'),
            ])
            ->orderBy('fecha_ingreso')
            ->orderBy('numero_folio')
            ->paginate((int) ($filtros['per_page'] ?? 25))
            ->withQueryString();

        return FolioSalidaDirectaPrefrioResource::collection($folios);
    }

    public function foliosDisponibles(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('gestionar-cargas');

        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'equivalente_a' => ['nullable', 'uuid', Rule::exists('folios', 'id')],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $busqueda = trim((string) ($filtros['q'] ?? ''));
        $folioOriginal = isset($filtros['equivalente_a'])
            ? Folio::query()->findOrFail($filtros['equivalente_a'])
            : null;

        $folios = Folio::query()
            ->where('activo', true)
            ->whereHas('temporada', fn (Builder $consulta): Builder => $consulta
                ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->where('estado_operacional', EstadoOperacionalFolio::Disponible->value)
            ->whereIn('tipo_bulto', [
                TipoBulto::Pallet->value,
                TipoBulto::Saldo->value,
            ])
            ->whereDoesntHave('asignacionCargaActual')
            ->when($folioOriginal, function (Builder $consulta, Folio $original): Builder {
                foreach ([
                    'tipo_bulto',
                    'condicion_sag_id',
                    'variedad',
                    'calibre',
                    'marca',
                    'exportadora',
                ] as $campo) {
                    $valor = $original->{$campo};
                    $consulta->where($campo, $valor instanceof \BackedEnum ? $valor->value : $valor);
                }

                return $consulta->where('id', '!=', $original->id);
            })
            ->whereHas(
                'ubicacionActual.posicion',
                fn (Builder $posicion): Builder => $posicion
                    ->where('estado', EstadoPosicion::Activa->value)
                    ->whereHas(
                        'camara',
                        fn (Builder $camara): Builder => $camara
                            ->where('estado', EstadoCamara::Activa->value)
                            ->where('contenido', ContenidoCamara::Productos->value),
                    ),
            )
            ->when(
                $busqueda !== '',
                fn (Builder $consulta): Builder => $consulta->where(
                    fn (Builder $coincidencia): Builder => $coincidencia
                        ->where('numero_folio', 'like', "%{$busqueda}%")
                        ->orWhere('tipo_bulto', 'like', "%{$busqueda}%")
                        ->orWhere('variedad', 'like', "%{$busqueda}%")
                        ->orWhere('calibre', 'like', "%{$busqueda}%")
                        ->orWhere('marca', 'like', "%{$busqueda}%")
                        ->orWhere('exportadora', 'like', "%{$busqueda}%")
                        ->orWhereHas(
                            'condicionSag',
                            fn (Builder $condicion): Builder => $condicion
                                ->where('codigo', 'like', "%{$busqueda}%")
                                ->orWhere('nombre', 'like', "%{$busqueda}%"),
                        )
                        ->orWhereHas(
                            'ubicacionActual.posicion',
                            fn (Builder $posicion): Builder => $posicion
                                ->where('etiqueta', 'like', "%{$busqueda}%")
                                ->orWhereHas(
                                    'camara',
                                    fn (Builder $camara): Builder => $camara
                                        ->where('codigo', 'like', "%{$busqueda}%")
                                        ->orWhere('nombre', 'like', "%{$busqueda}%"),
                                ),
                        ),
                ),
            )
            ->with([
                'condicionSag:id,codigo,nombre',
                'ubicacionActual.posicion.camara:id,codigo,nombre',
            ])
            ->orderBy('numero_folio')
            ->paginate((int) ($filtros['per_page'] ?? 25))
            ->withQueryString();

        return FolioDisponibleCargaResource::collection($folios);
    }

    public function registrarDespachoDirectoPrefrio(
        RegistrarDespachoDirectoPrefrioRequest $request,
        ServicioCarga $servicio,
    ): JsonResponse {
        $carga = $servicio->registrarDespachoDirectoPrefrio(
            $request->validated(),
            $request->user(),
        );

        return (new CargaResource($this->cargarDetalle($carga)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function store(
        CrearCargaRequest $request,
        ServicioCarga $servicio,
    ): JsonResponse {
        $carga = $servicio->crear(
            $request->validated(),
            $request->user(),
        );

        return (new CargaResource($this->cargarDetalle($carga)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Carga $carga): CargaResource
    {
        Gate::authorize('consultar-catalogo-cargas');

        return new CargaResource($this->cargarDetalle($carga));
    }

    public function update(
        ActualizarCargaRequest $request,
        Carga $carga,
        ServicioCarga $servicio,
    ): CargaResource {
        $actualizada = $servicio->actualizar(
            $carga,
            $request->validated(),
            $request->user(),
            $request->integer('version_esperada'),
        );

        return new CargaResource($this->cargarDetalle($actualizada));
    }

    public function agregarFolios(
        AgregarFoliosCargaRequest $request,
        Carga $carga,
        ServicioCarga $servicio,
    ): CargaResource {
        $actualizada = $servicio->agregarFolios(
            $carga,
            $request->validated('folios'),
            $request->user(),
            $request->integer('version_esperada'),
        );

        return new CargaResource($this->cargarDetalle($actualizada));
    }

    public function quitarFolio(
        VersionCargaRequest $request,
        Carga $carga,
        Folio $folio,
        ServicioCarga $servicio,
    ): CargaResource {
        Gate::authorize('gestionar-cargas');

        $actualizada = $servicio->quitarFolio(
            $carga,
            $folio,
            $request->user(),
            $request->integer('version_esperada'),
            $request->validated('motivo'),
        );

        return new CargaResource($this->cargarDetalle($actualizada));
    }

    public function publicar(
        VersionCargaRequest $request,
        Carga $carga,
        ServicioCarga $servicio,
    ): CargaResource {
        Gate::authorize('gestionar-cargas');

        $publicada = $servicio->publicar(
            $carga,
            $request->user(),
            $request->integer('version_esperada'),
        );

        return new CargaResource($this->cargarDetalle($publicada));
    }

    public function cancelar(
        VersionCargaRequest $request,
        Carga $carga,
        ServicioCarga $servicio,
    ): CargaResource {
        Gate::authorize('gestionar-cargas');

        $cancelada = $servicio->cancelar(
            $carga,
            $request->user(),
            $request->integer('version_esperada'),
            $request->validated('motivo'),
        );

        return new CargaResource($this->cargarDetalle($cancelada));
    }

    public function registrarCamionEnAnden(
        RegistrarPresenciaCargaAndenRequest $request,
        Carga $carga,
        ServicioPresenciaCargaAnden $servicio,
    ): CargaResource {
        $servicio->registrar($carga, $request->validated(), $request->user());

        return new CargaResource($this->cargarDetalle($carga->refresh()));
    }

    public function finalizarCamionEnAnden(
        FinalizarPresenciaCargaAndenRequest $request,
        Carga $carga,
        ServicioPresenciaCargaAnden $servicio,
    ): CargaResource {
        $servicio->finalizar($carga, $request->validated(), $request->user());

        return new CargaResource($this->cargarDetalle($carga->refresh()));
    }

    private function cargarDetalle(Carga $carga): Carga
    {
        return $carga
            ->load($this->relacionesDetalle())
            ->loadCount([
                'incidencias as incidencias_abiertas' => fn (Builder $consulta): Builder => $consulta
                    ->where('incidencias_carga_folio.estado', EstadoIncidenciaCarga::Abierta->value),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private function relacionesDetalle(): array
    {
        return [
            'temporada:id,codigo,nombre,activa',
            'camaraObjetivo:id,codigo,nombre',
            'andenPrevisto:id,codigo,nombre',
            'presenciaAndenActiva.anden:id,codigo,nombre',
            'presenciaAndenActiva.ingresadaPor:id,name',
            'embarque:id,carga_id,codigo,fecha_programada,hora_programada,modalidad,estado',
            'embarque.instructivos:id,embarque_id,orden,numero_externo',
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
        ];
    }

    /** @return array<int, string> */
    private function relacionesOperacion(): array
    {
        return [
            'camaraObjetivo:id,codigo,nombre',
            'andenPrevisto:id,codigo,nombre',
            'presenciaAndenActiva.anden:id,codigo,nombre',
            'presenciaAndenActiva.ingresadaPor:id,name',
            'embarque:id,carga_id,codigo,fecha_programada,hora_programada,modalidad,estado',
            'embarque.instructivos:id,embarque_id,orden,numero_externo',
            'asignacionesActuales.anden:id,codigo,nombre',
            'asignacionesActuales.folio.ubicacionActual.posicion.camara:id,codigo,nombre',
        ];
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
}
