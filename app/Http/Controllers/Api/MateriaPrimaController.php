<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoProductoMateriaPrima;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnularLoteMateriaPrimaRequest;
use App\Http\Requests\AsignarCamaraLoteMateriaPrimaRequest;
use App\Http\Requests\CompletarHidrocoolerMateriaPrimaRequest;
use App\Http\Requests\ConfirmarLoteMateriaPrimaRequest;
use App\Http\Requests\CorregirOrigenLoteMateriaPrimaRequest;
use App\Http\Requests\GuardarLoteMateriaPrimaRequest;
use App\Http\Requests\IniciarHidrocoolerMateriaPrimaRequest;
use App\Http\Requests\LiberarHidrocoolerMateriaPrimaRequest;
use App\Http\Resources\LoteMateriaPrimaResource;
use App\Models\CalibreValidacion;
use App\Models\Camara;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\LoteMateriaPrima;
use App\Models\SegmentoValidacionMp;
use App\Models\Temporada;
use App\Models\VariedadValidacion;
use App\Services\MateriaPrima\ServicioLoteMateriaPrima;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class MateriaPrimaController extends Controller
{
    public function resumen(): JsonResponse
    {
        Gate::authorize('consultar-materia-prima');
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        $base = LoteMateriaPrima::query()
            ->when($temporada, fn (Builder $consulta) => $consulta
                ->where('temporada_id', $temporada->id))
            ->when(! $temporada, fn (Builder $consulta) => $consulta->whereRaw('1 = 0'));

        return response()->json([
            'temporada' => $temporada ? [
                'id' => $temporada->id,
                'codigo' => $temporada->codigo,
                'nombre' => $temporada->nombre,
            ] : null,
            'segmentos_pendientes' => SegmentoValidacionMp::query()
                ->whereIn('estado', ['pendiente_lote', 'lotizacion_parcial'])
                ->whereHas('validacion.recepcion.temporada', fn (Builder $consulta) => $consulta
                    ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                ->count(),
            'lotes' => [
                'borradores' => (clone $base)
                    ->where('estado', EstadoLoteMateriaPrima::Borrador->value)->count(),
                'pendientes_hidrocooler' => (clone $base)->whereIn('estado', [
                    EstadoLoteMateriaPrima::PendienteHidrocooler,
                    EstadoLoteMateriaPrima::HidrocoolerEnCurso,
                ])->count(),
                'retenidos_hidrocooler' => (clone $base)
                    ->where('estado', EstadoLoteMateriaPrima::HidrocoolerRetenido->value)->count(),
                'pendientes_asignacion' => (clone $base)
                    ->where('estado', EstadoLoteMateriaPrima::PendienteAsignacion->value)->count(),
                'disponibles_proceso' => (clone $base)
                    ->where('estado', EstadoLoteMateriaPrima::DisponibleProceso->value)->count(),
                'asignados_camara' => (clone $base)
                    ->whereIn('estado', [
                        EstadoLoteMateriaPrima::AsignadoCamara->value,
                        EstadoLoteMateriaPrima::EntregaParcialProceso->value,
                        EstadoLoteMateriaPrima::EntregadoProceso->value,
                    ])->count(),
            ],
        ]);
    }

    public function catalogos(Request $request): Response
    {
        Gate::authorize('consultar-materia-prima');
        $temporada = app(ServicioTemporadaActiva::class)->buscar() ?? abort(404);
        $camaras = Camara::query()
            ->where('contenido', ContenidoCamara::MateriaPrima->value)
            ->where('estado', EstadoCamara::Activa->value)
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre', 'updated_at']);
        $etag = $this->etagCatalogos($temporada, $camaras);
        $respuestaCondicional = $this->configurarCacheCatalogos(response('', 200), $etag);

        if ($respuestaCondicional->isNotModified($request)) {
            return $respuestaCondicional;
        }

        $especies = EspecieValidacion::query()
            ->where('temporada_id', $temporada->id)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
        $variedades = VariedadValidacion::query()
            ->where('activo', true)
            ->whereIn('especie_validacion_id', $especies->pluck('id'))
            ->orderBy('nombre')
            ->get(['id', 'especie_validacion_id', 'nombre']);
        $calibres = CalibreValidacion::query()
            ->where('activo', true)
            ->whereIn('especie_validacion_id', $especies->pluck('id'))
            ->orderBy('nombre')
            ->get(['id', 'especie_validacion_id', 'nombre']);
        $csg = CsgValidacion::query()
            ->where('temporada_id', $temporada->id)
            ->where('activo', true)
            ->with([
                'variedades:id',
                'productor.clientes' => fn ($consulta) => $consulta
                    ->where('clientes_productores_csg.activo', true),
            ])
            ->orderBy('codigo')
            ->get()
            ->map(fn (CsgValidacion $item): array => [
                'id' => $item->id,
                'codigo' => $item->codigo,
                'predio' => $item->predio,
                'disponible_todos_clientes' => ! $item->productor_csg_id,
                'cliente_ids' => $item->productor?->clientes->pluck('id')->values() ?? [],
                'variedad_ids' => $item->variedades->pluck('id')->values(),
            ]);

        return $this->configurarCacheCatalogos(response()->json([
            'temporada' => [
                'id' => $temporada->id,
                'codigo' => $temporada->codigo,
                'nombre' => $temporada->nombre,
            ],
            'csg' => $csg,
            'especies' => $especies->map(fn (EspecieValidacion $especie): array => [
                'id' => $especie->id,
                'nombre' => $especie->nombre,
                'variedades' => $variedades
                    ->where('especie_validacion_id', $especie->id)
                    ->values()
                    ->map->only(['id', 'nombre']),
                'calibres' => $calibres
                    ->where('especie_validacion_id', $especie->id)
                    ->values()
                    ->map->only(['id', 'nombre']),
            ]),
            'tipos_producto' => array_column(TipoProductoMateriaPrima::cases(), 'value'),
            'envases_primarios' => array_column(TipoEnvaseRomana::cases(), 'value'),
            'envases_secundarios' => [
                TipoEnvaseRomana::Totes->value,
                TipoEnvaseRomana::Esponjas->value,
            ],
            'camaras' => $camaras
                ->map(fn (Camara $camara): array => [
                    'id' => $camara->id,
                    'codigo' => $camara->codigo,
                    'nombre' => $camara->nombre,
                ])
                ->values(),
        ]), $etag);
    }

    public function segmentosPendientes(ServicioLoteMateriaPrima $servicio): JsonResponse
    {
        Gate::authorize('consultar-materia-prima');
        $segmentos = SegmentoValidacionMp::query()
            ->where(function (Builder $consulta): void {
                $consulta->whereIn('estado', ['pendiente_lote', 'lotizacion_parcial'])
                    ->orWhereHas('lotesMateriaPrima', fn (Builder $lotes) => $lotes
                        ->where('estado', EstadoLoteMateriaPrima::Borrador->value));
            })
            ->whereHas('validacion.recepcion.temporada', fn (Builder $consulta) => $consulta
                ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->with([
                'envases',
                'csg',
                'variedad.especie',
                'validacion.recepcion.detallesEnvases',
                'validacion.recepcion.cliente',
                'lotesMateriaPrima.envasesDetalle',
                'lotesMateriaPrima' => fn ($consulta) => $consulta
                    ->where('estado', '!=', EstadoLoteMateriaPrima::Anulado->value),
            ])
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $segmentos->map(function (SegmentoValidacionMp $segmento) use ($servicio): array {
                $recepcion = $segmento->validacion->recepcion;
                $tipoBase = $recepcion->tipo_envase_calculo_neto
                    ?? $recepcion->tipo_envase_declarado?->value;
                $destarePendiente = $recepcion->peso_neto === null;
                $netoEstimado = null;
                $errorNeto = null;
                if (! $destarePendiente) {
                    try {
                        $netoEstimado = $segmento->lotesMateriaPrima->isNotEmpty()
                            ? (float) $segmento->lotesMateriaPrima->sum('kilos_netos_calculados')
                            : $servicio->netoEstimado($segmento, $recepcion);
                    } catch (\Throwable $exception) {
                        report($exception);
                        $errorNeto = 'No fue posible calcular el neto de este segmento. Revisa sus envases en Romana.';
                    }
                }
                $envases = $segmento->envases->map(function ($envase) use ($segmento): array {
                    $tipo = $envase->tipo_envase->value;
                    $reservada = $segmento->lotesMateriaPrima->sum(fn (LoteMateriaPrima $lote): int => (int) ($lote->envasesDetalle->first(fn ($item): bool => $item->tipo_envase->value === $tipo)?->cantidad ?? 0));

                    return [
                        'tipo_envase' => $tipo,
                        'cantidad' => $envase->cantidad,
                        'cantidad_reservada' => $reservada,
                        'cantidad_disponible' => max(0, $envase->cantidad - $reservada),
                    ];
                });

                return [
                    'id' => $segmento->id,
                    'secuencia' => $segmento->secuencia,
                    'estado' => $segmento->estado,
                    'motivos' => $segmento->motivos,
                    'csg' => $segmento->csg ? [
                        'id' => $segmento->csg->id,
                        'codigo' => $segmento->csg->codigo,
                        'predio' => $segmento->csg->predio,
                    ] : null,
                    'cuartel' => $segmento->cuartel,
                    'variedad' => $segmento->variedad ? [
                        'id' => $segmento->variedad->id,
                        'nombre' => $segmento->variedad->nombre,
                        'especie_id' => $segmento->variedad->especie_validacion_id,
                    ] : null,
                    'envases' => $envases,
                    'neto_estimado' => $netoEstimado,
                    'destare_pendiente' => $destarePendiente,
                    'error_neto' => $errorNeto,
                    'recepcion' => [
                        'id' => $recepcion->id,
                        'numero_recepcion' => $recepcion->numero_recepcion,
                        'numero_guia_despacho' => $recepcion->numero_guia_despacho,
                        'cliente' => [
                            'id' => $recepcion->cliente->id,
                            'codigo' => $recepcion->cliente->codigo,
                            'nombre' => $recepcion->cliente->nombre,
                        ],
                        'peso_neto' => $recepcion->peso_neto !== null
                            ? (float) $recepcion->peso_neto : null,
                        'tipo_envase_calculo_neto' => $tipoBase,
                        'cantidad_envase_calculo_neto' => $recepcion->cantidad_envase_calculo_neto,
                        'peso_neto_por_envase' => $recepcion->peso_neto_por_envase !== null
                            ? (float) $recepcion->peso_neto_por_envase : null,
                        'envases' => $recepcion->detallesEnvases->map(fn ($detalle): array => [
                            'tipo_envase' => $detalle->tipo_envase->value,
                            'tara_unitaria' => (float) ($detalle->tara_unitaria_salida ?? 0),
                        ])->values(),
                    ],
                ];
            }),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('consultar-materia-prima');
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        $lotes = LoteMateriaPrima::query()
            ->when($temporada, fn (Builder $consulta) => $consulta
                ->where('temporada_id', $temporada->id))
            ->when(! $temporada, fn (Builder $consulta) => $consulta->whereRaw('1 = 0'))
            ->when($request->filled('estado'), fn (Builder $consulta) => $consulta
                ->where('estado', $request->string('estado')->toString()))
            ->when($request->filled('buscar'), function (Builder $consulta) use ($request): void {
                $buscar = '%'.$request->string('buscar')->trim()->toString().'%';
                $consulta->where(function (Builder $filtro) use ($buscar): void {
                    $filtro->where('numero_lote', 'like', $buscar)
                        ->orWhere('ggn', 'like', $buscar)
                        ->orWhere('sdp', 'like', $buscar)
                        ->orWhereHas('recepcion', fn (Builder $recepcion) => $recepcion
                            ->where('numero_recepcion', 'like', $buscar));
                });
            })
            ->with($this->relaciones())
            ->latest()
            ->paginate(100);

        return LoteMateriaPrimaResource::collection($lotes);
    }

    public function show(LoteMateriaPrima $loteMateriaPrima): LoteMateriaPrimaResource
    {
        Gate::authorize('consultar-materia-prima');

        return new LoteMateriaPrimaResource(
            $loteMateriaPrima->load($this->relaciones(conEventos: true)),
        );
    }

    public function store(
        GuardarLoteMateriaPrimaRequest $request,
        ServicioLoteMateriaPrima $servicio,
    ): JsonResponse {
        $lote = $servicio->crear($request->validated(), $request->user());

        return (new LoteMateriaPrimaResource($lote))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        GuardarLoteMateriaPrimaRequest $request,
        LoteMateriaPrima $loteMateriaPrima,
        ServicioLoteMateriaPrima $servicio,
    ): LoteMateriaPrimaResource {
        return new LoteMateriaPrimaResource(
            $servicio->actualizar(
                $loteMateriaPrima,
                $request->validated(),
                $request->user(),
            ),
        );
    }

    public function corregirOrigen(
        CorregirOrigenLoteMateriaPrimaRequest $request,
        LoteMateriaPrima $loteMateriaPrima,
        ServicioLoteMateriaPrima $servicio,
    ): LoteMateriaPrimaResource {
        return new LoteMateriaPrimaResource(
            $servicio->corregirOrigen(
                $loteMateriaPrima,
                $request->validated(),
                $request->user(),
            ),
        );
    }

    public function confirmar(
        ConfirmarLoteMateriaPrimaRequest $request,
        LoteMateriaPrima $loteMateriaPrima,
        ServicioLoteMateriaPrima $servicio,
    ): LoteMateriaPrimaResource {
        $datos = $request->validated();

        return new LoteMateriaPrimaResource(
            $servicio->confirmar(
                $loteMateriaPrima,
                $datos['operacion_id'],
                $datos['version_conocida'],
                $request->user(),
            ),
        );
    }

    public function iniciarHidrocooler(
        IniciarHidrocoolerMateriaPrimaRequest $request,
        LoteMateriaPrima $loteMateriaPrima,
        ServicioLoteMateriaPrima $servicio,
    ): LoteMateriaPrimaResource {
        return new LoteMateriaPrimaResource(
            $servicio->iniciarHidrocooler(
                $loteMateriaPrima,
                $request->validated(),
                $request->user(),
            ),
        );
    }

    public function completarHidrocooler(
        CompletarHidrocoolerMateriaPrimaRequest $request,
        LoteMateriaPrima $loteMateriaPrima,
        ServicioLoteMateriaPrima $servicio,
    ): LoteMateriaPrimaResource {
        return new LoteMateriaPrimaResource(
            $servicio->completarHidrocooler(
                $loteMateriaPrima,
                $request->validated(),
                $request->user(),
            ),
        );
    }

    public function liberarHidrocooler(
        LiberarHidrocoolerMateriaPrimaRequest $request,
        LoteMateriaPrima $loteMateriaPrima,
        ServicioLoteMateriaPrima $servicio,
    ): LoteMateriaPrimaResource {
        return new LoteMateriaPrimaResource(
            $servicio->liberarHidrocooler(
                $loteMateriaPrima,
                $request->validated(),
                $request->user(),
            ),
        );
    }

    public function asignarCamara(
        AsignarCamaraLoteMateriaPrimaRequest $request,
        LoteMateriaPrima $loteMateriaPrima,
        ServicioLoteMateriaPrima $servicio,
    ): LoteMateriaPrimaResource {
        return new LoteMateriaPrimaResource(
            $servicio->asignarCamara(
                $loteMateriaPrima,
                $request->validated(),
                $request->user(),
            ),
        );
    }

    public function anular(
        AnularLoteMateriaPrimaRequest $request,
        LoteMateriaPrima $loteMateriaPrima,
        ServicioLoteMateriaPrima $servicio,
    ): LoteMateriaPrimaResource {
        $datos = $request->validated();

        return new LoteMateriaPrimaResource(
            $servicio->anular(
                $loteMateriaPrima,
                $datos['operacion_id'],
                $datos['motivo'],
                $request->user(),
            ),
        );
    }

    /** @return array<int, string> */
    private function relaciones(bool $conEventos = false): array
    {
        $relaciones = [
            'segmento.envases',
            'envasesDetalle',
            'recepcion',
            'temporada',
            'cliente',
            'csg',
            'especie',
            'variedad',
            'calibre',
            'creadoPor',
            'actualizadoPor',
            'confirmadoPor',
            'anuladoPor',
            'hidrocooler.iniciadoPor',
            'hidrocooler.completadoPor',
            'hidrocooler.liberadoPor',
            'asignacionCamara.camara',
            'asignacionCamara.asignadoPor',
        ];
        if ($conEventos) {
            $relaciones[] = 'eventos.usuario';
        }

        return $relaciones;
    }

    private function etagCatalogos(Temporada $temporada, Collection $camaras): string
    {
        $huella = json_encode([
            'temporada_id' => $temporada->id,
            'version_catalogo' => $temporada->version_catalogo,
            'tipos_producto' => array_column(TipoProductoMateriaPrima::cases(), 'value'),
            'envases_primarios' => array_column(TipoEnvaseRomana::cases(), 'value'),
            'camaras' => $camaras
                ->map(fn (Camara $camara): array => [
                    'id' => $camara->id,
                    'codigo' => $camara->codigo,
                    'nombre' => $camara->nombre,
                    'actualizada_at' => $camara->updated_at?->toAtomString(),
                ])
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR);

        return 'materia-prima-catalogos-'.hash('sha256', $huella);
    }

    private function configurarCacheCatalogos(Response $respuesta, string $etag): Response
    {
        $respuesta->setEtag($etag);
        $respuesta->setPrivate();
        $respuesta->headers->addCacheControlDirective('no-cache');
        $respuesta->setVary('Authorization');
        $respuesta->headers->set('Access-Control-Expose-Headers', 'ETag');

        return $respuesta;
    }
}
