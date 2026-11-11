<?php

namespace App\Http\Controllers\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoLoteInspeccionSag;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoInspeccionSag;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\TipoAprobacionSag;
use App\Enums\TipoBulto;
use App\Enums\TipoDestinoSag;
use App\Enums\TipoLoteInspeccionSag;
use App\Http\Controllers\Controller;
use App\Models\BloqueMercado;
use App\Models\Folio;
use App\Models\LoteInspeccionSag;
use App\Models\Pais;
use App\Models\ResultadoDestinoInspeccionSag;
use App\Services\InspeccionSag\ServicioEstadoSagFolio;
use App\Services\InspeccionSag\ServicioInspeccionSag;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class InspeccionSagController extends Controller
{
    public function resumen(Request $request): JsonResponse
    {
        $activos = collect(EstadoLoteInspeccionSag::cases())->filter->esActivo()->map->value->all();

        return response()->json([
            'lotes_activos' => LoteInspeccionSag::query()
                ->whereHas('temporada', fn (Builder $consulta): Builder => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                ->whereIn('estado', $activos)->count(),
            'pallets_en_inspeccion' => Folio::query()
                ->whereHas('inspeccionesSag.lote', fn (Builder $consulta): Builder => $consulta
                    ->whereIn('estado', $activos)
                    ->whereHas('temporada', fn (Builder $temporada): Builder => $temporada->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id)))
                ->count(),
            'finalizados_hoy' => LoteInspeccionSag::query()
                ->whereHas('temporada', fn (Builder $consulta): Builder => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                ->where('estado', EstadoLoteInspeccionSag::Finalizado)
                ->whereDate('finalizado_at', today())
                ->count(),
            'autorizaciones_hoy' => ResultadoDestinoInspeccionSag::query()
                ->where('resultado', ResultadoInspeccionSag::Aprobado)
                ->whereDate('resuelto_at', today())
                ->count(),
        ]);
    }

    public function catalogos(): JsonResponse
    {
        $bloques = BloqueMercado::query()->with('paises:id,iso_alpha2,nombre_es')
            ->where('activo', true)->orderBy('nombre')->get();

        return response()->json([
            'tipos_lote' => collect(TipoLoteInspeccionSag::cases())->map(fn ($tipo): array => [
                'value' => $tipo->value,
                'label' => $tipo->etiqueta(),
                'tipo_aprobacion' => $tipo->aprobacionPredeterminada()?->value,
            ])->all(),
            'tipos_aprobacion' => collect(TipoAprobacionSag::cases())->map(fn ($tipo): array => [
                'value' => $tipo->value,
                'label' => $tipo->etiqueta(),
            ])->all(),
            'bloques' => $bloques->map(fn (BloqueMercado $bloque): array => [
                'id' => $bloque->id,
                'codigo' => $bloque->codigo,
                'nombre' => $bloque->nombre,
                'paises' => $bloque->paises->map(fn (Pais $pais): array => [
                    'id' => $pais->id,
                    'codigo' => $pais->iso_alpha2,
                    'nombre' => $pais->nombre_es,
                ])->values(),
            ])->values(),
            'paises' => Pais::query()->where('activo', true)->orderBy('nombre_es')->get()
                ->map(fn (Pais $pais): array => [
                    'id' => $pais->id,
                    'codigo' => $pais->iso_alpha2,
                    'nombre' => $pais->nombre_es,
                    'es_iso_oficial' => $pais->es_iso_oficial,
                ]),
        ]);
    }

    public function opcionesFolios(): JsonResponse
    {
        // Las opciones salen de los pallets elegibles, no del catálogo de combinaciones: ese catálogo
        // cruza artículos × orígenes de la temporada, crece al cuadrado y la consulta no terminaba.
        // Así además solo se ofrece lo que la búsqueda de pallets puede encontrar.
        $filas = $this->consultaElegibles()
            ->toBase()
            ->join('validaciones_pallet as vp', fn ($join) => $join
                ->on('vp.folio_id', '=', 'folios.id')
                ->where('vp.estado', EstadoValidacionPallet::Aceptada->value)
                ->where('vp.resultado', ResultadoValidacionPallet::Aprobado->value))
            ->join('articulos_validacion as a', 'a.id', '=', 'vp.articulo_validacion_id')
            ->join('especies_validacion as e', fn ($join) => $join->on('e.id', '=', 'a.especie_validacion_id')->where('e.activo', true))
            ->leftJoin('variedades_validacion as v', fn ($join) => $join->on('v.id', '=', 'a.variedad_validacion_id')->where('v.activo', true))
            ->join('origenes_validacion as o', 'o.id', '=', 'vp.origen_validacion_id')
            ->join('clientes_validacion as cc', 'cc.id', '=', 'o.cliente_validacion_id')
            ->join('clientes as c', fn ($join) => $join->on('c.id', '=', 'cc.cliente_id')->where('c.activo', true))
            ->leftJoin('csg_validacion as csg', fn ($join) => $join->on('csg.id', '=', 'o.csg_validacion_id')->where('csg.activo', true))
            ->distinct()
            ->get([
                'c.id as cliente_id', 'c.codigo as cliente_codigo', 'c.nombre as cliente_nombre',
                'e.id as especie_id', 'e.nombre as especie_nombre',
                'v.id as variedad_id', 'v.nombre as variedad_nombre',
                'csg.id as csg_id', 'csg.codigo as csg_codigo',
            ]);

        $clientes = $filas
            ->groupBy(fn (object $fila): string => (string) $fila->cliente_id)
            ->map(function ($asociadas): array {
                $cliente = $asociadas->first();
                $especies = $asociadas
                    ->groupBy(fn (object $fila): string => (string) $fila->especie_id)
                    ->map(fn ($porEspecie): array => [
                        'id' => $porEspecie->first()->especie_id,
                        'nombre' => $porEspecie->first()->especie_nombre,
                        'variedades' => $porEspecie
                            ->filter(fn (object $fila): bool => filled($fila->variedad_id))
                            ->map(fn (object $fila): array => ['id' => $fila->variedad_id, 'nombre' => $fila->variedad_nombre])
                            ->unique('id')->sortBy('nombre')->values(),
                        'csg' => $porEspecie
                            ->filter(fn (object $fila): bool => filled($fila->csg_id))
                            ->map(fn (object $fila): array => ['id' => $fila->csg_id, 'codigo' => $fila->csg_codigo])
                            ->unique('id')->sortBy('codigo')->values(),
                    ])
                    ->sortBy('nombre')->values();

                return [
                    'id' => $cliente->cliente_id,
                    'codigo' => $cliente->cliente_codigo,
                    'nombre' => $cliente->cliente_nombre,
                    'especies' => $especies,
                ];
            })
            ->sortBy('nombre')->values();

        return response()->json([
            'clientes' => $clientes,
            'condiciones_termicas' => collect(CondicionTermicaFolio::cases())->map(fn ($condicion): array => [
                'value' => $condicion->value,
                'label' => str($condicion->value)->replace('_', ' ')->title()->toString(),
            ]),
        ]);
    }

    public function folios(Request $request, ServicioEstadoSagFolio $estadoSag): JsonResponse
    {
        $datos = $request->validate([
            'folio' => ['nullable', 'string', 'max:100'],
            'cliente' => [
                'required_without:folio',
                'nullable',
                'uuid',
                Rule::exists('clientes', 'id')->where('activo', true),
            ],
            'especie' => [
                'required_without:folio',
                'nullable',
                'uuid',
                Rule::exists('especies_validacion', 'id')->where('activo', true),
            ],
            'variedad' => ['nullable', 'uuid', Rule::exists('variedades_validacion', 'id')->where('activo', true)],
            'condicion_sag' => ['nullable', Rule::in(['con', 'sin'])],
            'csg' => ['nullable', 'uuid', Rule::exists('csg_validacion', 'id')->where('activo', true)],
            'fecha_ingreso' => ['nullable', 'date'],
            'condicion_termica' => ['nullable', Rule::enum(CondicionTermicaFolio::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $consulta = $this->consultaElegibles();

        if (filled($datos['folio'] ?? null)) {
            $consulta
                ->where('numero_folio', trim($datos['folio']))
                ->whereHas('validacionPallet.origen.clienteCatalogo.cliente');
        } else {
            $consulta->whereHas('validacionPallet', function (Builder $validacion) use ($datos): void {
                $validacion
                    ->whereHas('origen.clienteCatalogo', fn (Builder $cliente): Builder => $cliente
                        ->where('cliente_id', $datos['cliente']))
                    ->whereHas('articulo', function (Builder $articulo) use ($datos): void {
                        $articulo->where('especie_validacion_id', $datos['especie'])
                            ->when($datos['variedad'] ?? null, fn (Builder $consulta, string $valor): Builder => $consulta
                                ->where('variedad_validacion_id', $valor));
                    })
                    ->when($datos['csg'] ?? null, fn (Builder $consulta, string $valor): Builder => $consulta
                        ->whereHas('origen', fn (Builder $origen): Builder => $origen
                            ->where('csg_validacion_id', $valor)));
            });

            $consulta
                ->when(($datos['condicion_sag'] ?? null) === 'con', fn (Builder $consulta): Builder => $consulta
                    ->whereNotNull('condicion_sag_id'))
                ->when(($datos['condicion_sag'] ?? null) === 'sin', fn (Builder $consulta): Builder => $consulta
                    ->whereNull('condicion_sag_id'))
                ->when($datos['fecha_ingreso'] ?? null, fn (Builder $consulta, string $valor): Builder => $consulta
                    ->whereDate('fecha_ingreso', $valor))
                ->when($datos['condicion_termica'] ?? null, fn (Builder $consulta, string $valor): Builder => $consulta
                    ->where('condicion_termica', $valor));
        }

        $folios = $consulta
            ->with($this->relacionesFolio())
            ->orderBy('numero_folio')
            ->paginate($datos['per_page'] ?? 50);

        $folios->through(fn (Folio $folio): array => $this->serializarFolio($folio, $estadoSag));

        return response()->json($folios);
    }

    public function index(Request $request, ServicioInspeccionSag $servicio): JsonResponse
    {
        $datos = $request->validate([
            'estado' => ['nullable', Rule::enum(EstadoLoteInspeccionSag::class)],
            'tipo' => ['nullable', Rule::enum(TipoLoteInspeccionSag::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $lotes = LoteInspeccionSag::query()
            ->whereHas('temporada', fn (Builder $consulta): Builder => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->when($datos['estado'] ?? null, fn (Builder $consulta, string $estado): Builder => $consulta->where('estado', $estado))
            ->when($datos['tipo'] ?? null, fn (Builder $consulta, string $tipo): Builder => $consulta->where('tipo', $tipo))
            ->withCount('folios')
            ->with(['destinos', 'creadoPor:id,name', 'cliente:id,codigo,nombre'])
            ->latest()
            ->paginate($datos['per_page'] ?? 25);
        $lotes->through(fn (LoteInspeccionSag $lote): array => $this->serializarLote($lote, false));

        return response()->json($lotes);
    }

    public function show(LoteInspeccionSag $loteInspeccionSag, ServicioInspeccionSag $servicio): JsonResponse
    {
        return response()->json($this->serializarLote($servicio->cargar($loteInspeccionSag), true));
    }

    public function store(Request $request, ServicioInspeccionSag $servicio): JsonResponse
    {
        $datos = $request->validate([
            'operacion_id' => ['nullable', 'uuid'],
            'tipo' => ['required', Rule::enum(TipoLoteInspeccionSag::class)],
            'cantidad_solicitada' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'numero_inspeccion_sag' => ['nullable', 'string', 'max:100'],
            'referencia_correo' => ['nullable', 'string', 'max:250'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'folios' => ['required', 'array', 'min:1', 'max:1000'],
            'folios.*' => ['required', 'uuid', 'distinct'],
            'destinos' => ['required', 'array', 'min:1'],
            'destinos.*.tipo' => ['required', Rule::enum(TipoDestinoSag::class)],
            'destinos.*.id' => ['required', 'uuid'],
        ]);
        $lote = $servicio->crear($datos, $request->user());

        return response()->json($this->serializarLote($lote, true), Response::HTTP_CREATED);
    }

    public function iniciar(Request $request, LoteInspeccionSag $loteInspeccionSag, ServicioInspeccionSag $servicio): JsonResponse
    {
        return response()->json($this->serializarLote($servicio->iniciar($loteInspeccionSag, $request->user()), true));
    }

    public function resolver(
        Request $request,
        LoteInspeccionSag $loteInspeccionSag,
        ResultadoDestinoInspeccionSag $resultado,
        ServicioInspeccionSag $servicio,
    ): JsonResponse {
        $datos = $request->validate([
            'resultado' => ['required', Rule::in([
                ResultadoInspeccionSag::Aprobado->value,
                ResultadoInspeccionSag::Segregado->value,
                ResultadoInspeccionSag::Rechazado->value,
                ResultadoInspeccionSag::SinResolucion->value,
            ])],
            'tipo_aprobacion' => ['nullable', Rule::enum(TipoAprobacionSag::class)],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($this->serializarLote(
            $servicio->resolver($loteInspeccionSag, $resultado, $datos, $request->user()),
            true,
        ));
    }

    public function finalizar(Request $request, LoteInspeccionSag $loteInspeccionSag, ServicioInspeccionSag $servicio): JsonResponse
    {
        return response()->json($this->serializarLote($servicio->finalizar($loteInspeccionSag, $request->user()), true));
    }

    public function cancelar(Request $request, LoteInspeccionSag $loteInspeccionSag, ServicioInspeccionSag $servicio): JsonResponse
    {
        return response()->json($this->serializarLote($servicio->cancelar($loteInspeccionSag, $request->user()), true));
    }

    private function consultaElegibles(): Builder
    {
        $estadosTerminales = [
            EstadoOperacionalFolio::Anulado,
            EstadoOperacionalFolio::RetiradoDefinitivo,
            EstadoOperacionalFolio::Despachado,
            EstadoOperacionalFolio::Agotado,
        ];
        $estadosLoteActivos = collect(EstadoLoteInspeccionSag::cases())->filter->esActivo()->map->value->all();

        return Folio::query()
            ->where('folios.activo', true)
            ->where('folios.tipo_bulto', TipoBulto::Pallet)
            ->whereNotIn('folios.estado_operacional', $estadosTerminales)
            ->whereDoesntHave('material')
            ->whereHas('temporada', fn (Builder $consulta): Builder => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->whereDoesntHave('inspeccionesSag.lote', fn (Builder $consulta): Builder => $consulta
                ->whereIn('estado', $estadosLoteActivos));
    }

    /** @return array<int, string> */
    private function relacionesFolio(): array
    {
        return [
            'condicionSag:id,codigo,nombre',
            'ubicacionActual.camara:id,codigo,nombre',
            'ubicacionActual.posicion:id,etiqueta',
            'autorizacionesSagActivas',
            'inspeccionesSag.lote',
            'inspeccionesSag.resultados.destino',
            'validacionPallet.articulo.especieCatalogo:id,nombre',
            'validacionPallet.articulo.variedadCatalogo:id,especie_validacion_id,nombre',
            'validacionPallet.origen.clienteCatalogo.cliente:id,codigo,nombre',
            'validacionPallet.origen.csgCatalogo:id,codigo',
        ];
    }

    /** @return array<string, mixed> */
    private function serializarFolio(Folio $folio, ServicioEstadoSagFolio $estadoSag): array
    {
        $datos = $folio->datos_externos ?? [];
        $articulo = $folio->validacionPallet?->articulo;
        $origen = $folio->validacionPallet?->origen;
        $cliente = $origen?->clienteCatalogo?->cliente;

        return [
            'id' => $folio->id,
            'folio' => $folio->numero_folio,
            'cliente_id' => $cliente?->id,
            'cliente' => $cliente?->nombre ?? $folio->exportadora,
            'especie_id' => $articulo?->especieCatalogo?->id,
            'especie' => $articulo?->especieCatalogo?->nombre ?? ($datos['especie'] ?? null),
            'variedad_id' => $articulo?->variedadCatalogo?->id,
            'variedad' => $articulo?->variedadCatalogo?->nombre ?? $folio->variedad,
            'condicion_sag_origen' => $folio->condicionSag?->nombre,
            'csg_id' => $origen?->csgCatalogo?->id,
            'csg' => $origen?->csgCatalogo?->codigo ?? ($datos['csg'] ?? null),
            'fecha_ingreso' => $folio->fecha_ingreso?->toDateString(),
            'condicion_termica' => $folio->condicion_termica->value,
            'cantidad_cajas' => $datos['cantidad_cajas'] ?? null,
            'camara' => $folio->ubicacionActual?->camara?->codigo,
            'posicion' => $folio->ubicacionActual?->posicion?->etiqueta,
            'sag' => $estadoSag->resumir($folio),
        ];
    }

    /** @return array<string, mixed> */
    private function serializarLote(LoteInspeccionSag $lote, bool $detalle): array
    {
        $base = [
            'id' => $lote->id,
            'codigo' => $lote->codigo,
            'numero_inspeccion_sag' => $lote->numero_inspeccion_sag,
            'cliente' => $lote->cliente ? [
                'id' => $lote->cliente->id,
                'codigo' => $lote->cliente->codigo,
                'nombre' => $lote->cliente->nombre,
            ] : null,
            'tipo' => $lote->tipo->value,
            'estado' => $lote->estado->value,
            'cantidad_solicitada' => $lote->cantidad_solicitada,
            'cantidad_folios' => $lote->folios_count ?? $lote->folios->count(),
            'referencia_correo' => $lote->referencia_correo,
            'observacion' => $lote->observacion,
            'creado_por' => $lote->creadoPor?->name,
            'creado_at' => $lote->created_at?->toAtomString(),
            'iniciado_at' => $lote->iniciado_at?->toAtomString(),
            'finalizado_at' => $lote->finalizado_at?->toAtomString(),
            'destinos' => $lote->destinos->map(fn ($destino): array => [
                'id' => $destino->id,
                'tipo' => $destino->tipo_destino->value,
                'codigo' => $destino->destino_snapshot['codigo'] ?? null,
                'nombre' => $destino->destino_snapshot['nombre'] ?? null,
                'miembros' => $destino->miembros_snapshot,
            ])->values(),
        ];

        if (! $detalle) {
            return $base;
        }

        $planPreparacion = $lote->planPreparacion;

        return $base + [
            'preparacion_fisica' => $planPreparacion ? [
                'plan_id' => $planPreparacion->id,
                'estado' => $planPreparacion->estado->value,
                'prioridad' => $planPreparacion->prioridad->value,
                'capacidad' => $planPreparacion->contexto ?? [],
                'programado_at' => $planPreparacion->programado_at?->toAtomString(),
                'completado_at' => $planPreparacion->completado_at?->toAtomString(),
                'cancelado_at' => $planPreparacion->cancelado_at?->toAtomString(),
            ] : null,
            'folios' => $lote->folios->map(fn ($asignacion): array => [
                'id' => $asignacion->id,
                'estado' => $asignacion->estado->value,
                'estado_sag_anterior' => $asignacion->estado_sag_anterior,
                'folio' => [
                    'id' => $asignacion->folio->id,
                    'numero' => $asignacion->folio->numero_folio,
                    'cliente' => $asignacion->folio->exportadora,
                    'especie' => $asignacion->folio->datos_externos['especie'] ?? null,
                    'variedad' => $asignacion->folio->variedad,
                    'camara' => $asignacion->folio->ubicacionActual?->camara?->codigo,
                    'posicion' => $asignacion->folio->ubicacionActual?->posicion?->etiqueta,
                ],
                'resultados' => $asignacion->resultados->map(fn ($resultado): array => [
                    'id' => $resultado->id,
                    'destino' => $resultado->destino->destino_snapshot,
                    'resultado' => $resultado->resultado->value,
                    'tipo_aprobacion' => $resultado->tipo_aprobacion?->value,
                    'observacion' => $resultado->observacion,
                    'resuelto_at' => $resultado->resuelto_at?->toAtomString(),
                ])->values(),
            ])->values(),
        ];
    }
}
