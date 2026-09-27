<?php

namespace App\Http\Controllers\Api;

use App\Enums\DecisionArbitrajeManiobra;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPasoManiobra;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanOperacionalResource;
use App\Http\Resources\TareaMovimientoResource;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\SesionEstiba;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Services\Autenticacion\ContextoOperacional;
use App\Services\Estiba\ServicioConfirmacionInicioTarea;
use App\Services\Estiba\ServicioManiobrasOperacionales;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioReservasTareasMovimiento;
use App\Services\Planificador\ServicioArbitrajeManiobras;
use App\Services\Planificador\ServicioDesplieguePlanificador;
use App\Services\Planificador\ServicioEstadoArbitrajePlanificador;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PlanOperacionalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'tipo' => ['nullable', Rule::enum(TipoPlanOperacional::class)],
            'estado' => ['nullable', Rule::enum(EstadoPlanOperacional::class)],
            'prioridad' => ['nullable', Rule::enum(PrioridadOperacional::class)],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $planes = PlanOperacional::query()
            ->whereHas('temporada', fn (Builder $consulta): Builder => $consulta->where('activa', true))
            ->when(
                $filtros['tipo'] ?? null,
                fn (Builder $consulta, string $tipo): Builder => $consulta->where('tipo', $tipo),
            )
            ->when(
                $filtros['estado'] ?? null,
                fn (Builder $consulta, string $estado): Builder => $consulta->where('estado', $estado),
                fn (Builder $consulta): Builder => $consulta->whereNotIn('estado', [
                    EstadoPlanOperacional::Completado->value,
                    EstadoPlanOperacional::Cancelado->value,
                ]),
            )
            ->when(
                $filtros['prioridad'] ?? null,
                fn (Builder $consulta, string $prioridad): Builder => $consulta->where('prioridad', $prioridad),
            )
            ->with(['temporada:id,codigo,nombre', 'creadoPor:id,name', 'iniciadoPor:id,name'])
            ->withCount('tareas')
            ->orderByRaw($this->ordenPrioridad())
            ->orderBy('programado_at')
            ->paginate((int) ($filtros['per_page'] ?? 25))
            ->withQueryString();

        return PlanOperacionalResource::collection($planes);
    }

    public function show(
        PlanOperacional $planOperacional,
        ServicioReservasTareasMovimiento $reservas,
    ): PlanOperacionalResource {
        abort_unless($planOperacional->temporada()->where('activa', true)->exists(), 404);
        $reservas->expirarVencidas();

        return new PlanOperacionalResource($planOperacional->load([
            'temporada:id,codigo,nombre',
            'creadoPor:id,name',
            'iniciadoPor:id,name',
            'tareas.folio:id,numero_folio,tipo_bulto,variedad,calibre,marca,exportadora,fecha_ingreso',
            'tareas.camaraOrigen:id,nombre',
            'tareas.posicionOrigen:id,camara_id,etiqueta,banda,posicion,nivel',
            'tareas.camaraDestino:id,nombre',
            'tareas.posicionDestino:id,camara_id,etiqueta,banda,posicion,nivel',
            'tareas.responsable:id,name',
            'tareas.dispositivo:id,codigo,nombre',
            'tareas.reservaActiva:id,tarea_movimiento_id,bloqueo_tarea_id,bloqueo_posicion_id,estado,reservada_at,renovada_at,vence_at,version',
            'tareas.maniobraOperacional:id,plan_operacional_id,estado,prioridad,candidate_key,titulo,secuencia_actual,costo_movimientos,beneficio_estimado,riesgo_operacional,responsable_user_id,dispositivo_id,version,contexto',
            'tareas.maniobraOperacional.objetivos:id,tipo,estado,prioridad,titulo',
            'tareas.maniobraOperacional.ultimaDecisionArbitraje',
            'tareas.maniobraOperacional.ultimaDecisionArbitraje.ciclo:id,snapshot_version,created_at',
            ...$this->relacionesDetalleManiobra('tareas.maniobraOperacional'),
        ]));
    }

    public function snapshot(
        PlanOperacional $planOperacional,
        ServicioReservasTareasMovimiento $reservas,
        ServicioPlanesOperacionales $servicio,
    ): JsonResponse {
        abort_unless($planOperacional->temporada()->where('activa', true)->exists(), 404);
        $reservas->expirarVencidas();

        return response()->json(['data' => $servicio->snapshot($planOperacional)]);
    }

    public function tareas(
        Request $request,
        ServicioReservasTareasMovimiento $reservas,
        ServicioArbitrajeManiobras $arbitraje,
        ServicioEstadoArbitrajePlanificador $estadoArbitraje,
    ): AnonymousResourceCollection {
        $filtros = $request->validate([
            'estado' => ['nullable', Rule::enum(EstadoTareaMovimiento::class)],
            'prioridad' => ['nullable', Rule::enum(PrioridadOperacional::class)],
            'asignacion' => ['nullable', Rule::in(['disponibles', 'mias', 'todas'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $asignacion = $filtros['asignacion'] ?? 'disponibles';
        $usuarioId = $request->user()->id;
        $reservas->expirarVencidas();
        $cicloArbitraje = null;
        $proyeccionArbitraje = null;
        $maniobrasPublicables = null;
        $ordenArbitraje = [];

        if ($asignacion === 'disponibles' && config('planificador.mode') === 'guided') {
            $temporada = Temporada::query()->where('activa', true)->first();
            if ($temporada) {
                $proyeccionArbitraje = $estadoArbitraje->consultar($temporada);
                $cicloArbitraje = $proyeccionArbitraje['vigente']
                    ? $proyeccionArbitraje['ciclo']
                    : null;
                $maniobrasPublicables = $cicloArbitraje
                    ? $arbitraje->idsPublicables($cicloArbitraje)->all()
                    : [];
                $ordenArbitraje = $cicloArbitraje
                    ? $cicloArbitraje->decisiones
                        ->whereIn('maniobra_operacional_id', $maniobrasPublicables)
                        ->sortBy('orden')
                        ->pluck('maniobra_operacional_id')
                        ->values()
                        ->all()
                    : [];
            }
        }

        $consultaTareas = TareaMovimiento::query()
            ->whereHas('planOperacional.temporada', fn (Builder $consulta): Builder => $consulta->where('activa', true))
            ->whereHas('planOperacional', fn (Builder $consulta): Builder => $consulta->whereNotIn('estado', [
                EstadoPlanOperacional::Pausado->value,
                EstadoPlanOperacional::Completado->value,
                EstadoPlanOperacional::Cancelado->value,
            ]))
            ->when(
                $filtros['estado'] ?? null,
                fn (Builder $consulta, string $estado): Builder => $consulta->where('estado', $estado),
                fn (Builder $consulta): Builder => $consulta->where(
                    function (Builder $estados) use ($asignacion, $usuarioId): void {
                        $estados->whereIn('estado', [
                            EstadoTareaMovimiento::Pendiente->value,
                            EstadoTareaMovimiento::Asumida->value,
                            EstadoTareaMovimiento::EnProceso->value,
                        ]);

                        if ($asignacion === 'mias') {
                            $estados->orWhere(function (Builder $pausadas) use ($usuarioId): void {
                                $pausadas
                                    ->where('estado', EstadoTareaMovimiento::Bloqueada->value)
                                    ->where('responsable_user_id', $usuarioId)
                                    ->whereHas(
                                        'maniobraOperacional',
                                        fn (Builder $maniobra): Builder => $maniobra->where(
                                            'estado',
                                            EstadoManiobraOperacional::PausadaDiscrepancia->value,
                                        ),
                                    );
                            });
                        }
                    },
                ),
            )
            ->when(
                $filtros['prioridad'] ?? null,
                fn (Builder $consulta, string $prioridad): Builder => $consulta->where('prioridad', $prioridad),
            )
            ->when(
                $asignacion === 'disponibles',
                fn (Builder $consulta): Builder => $consulta->whereNull('responsable_user_id'),
            )
            ->when(
                $asignacion === 'mias',
                fn (Builder $consulta): Builder => $consulta->where('responsable_user_id', $usuarioId),
            )
            ->when(
                $maniobrasPublicables !== null,
                fn (Builder $consulta): Builder => $consulta->where(function (Builder $publicables) use (
                    $maniobrasPublicables,
                ): void {
                    $publicables
                        ->whereIn('maniobra_operacional_id', $maniobrasPublicables)
                        ->orWhereNull('maniobra_operacional_id')
                        ->orWhereHas(
                            'planOperacional',
                            fn (Builder $plan): Builder => $plan->where(
                                'tipo',
                                TipoPlanOperacional::RecepcionRepaletizaje->value,
                            ),
                        );
                }),
            )
            ->with($this->relacionesTarea());

        $consultaTareas->orderByRaw($this->ordenPrioridad());
        if ($ordenArbitraje !== []) {
            $casos = collect($ordenArbitraje)
                ->map(fn (string $id, int $indice): string => "WHEN ? THEN {$indice}")
                ->implode(' ');
            $consultaTareas->orderByRaw(
                "CASE maniobra_operacional_id {$casos} ELSE 999999 END",
                $ordenArbitraje,
            );
        }

        $tareas = $consultaTareas
            ->orderBy('created_at')
            ->orderBy('secuencia')
            ->paginate((int) ($filtros['per_page'] ?? 25))
            ->withQueryString();

        $respuesta = TareaMovimientoResource::collection($tareas);
        if ($proyeccionArbitraje) {
            unset($proyeccionArbitraje['ciclo']);
            $respuesta->additional([
                'arbitraje' => [
                    'ciclo_id' => $cicloArbitraje?->id,
                    'snapshot_version' => $cicloArbitraje?->snapshot_version,
                    'capacidad_ejecucion' => $cicloArbitraje?->capacidad_ejecucion,
                    'frontera_max' => $cicloArbitraje?->frontera_max,
                    'vigencia' => $proyeccionArbitraje,
                    'seleccionadas' => $cicloArbitraje?->decisiones?->where(
                        'decision',
                        DecisionArbitrajeManiobra::Seleccionada,
                    )?->count() ?? 0,
                    'alternativas' => $cicloArbitraje?->decisiones?->where(
                        'decision',
                        DecisionArbitrajeManiobra::Alternativa,
                    )?->count() ?? 0,
                    'fuera_rollout' => $cicloArbitraje?->decisiones?->where(
                        'decision',
                        DecisionArbitrajeManiobra::FueraRollout,
                    )?->count() ?? 0,
                ],
            ]);
        }

        return $respuesta;
    }

    public function asumir(
        Request $request,
        TareaMovimiento $tareaMovimiento,
        ContextoOperacional $contexto,
        ServicioPlanesOperacionales $servicio,
    ): TareaMovimientoResource {
        [$usuario, $dispositivo] = $contexto->obtener($request);

        return new TareaMovimientoResource(
            $servicio->asumir($tareaMovimiento, $usuario, $dispositivo),
        );
    }

    public function materializarFrontera(
        Request $request,
        PlanOperacional $planOperacional,
        ContextoOperacional $contexto,
        ServicioPlanesOperacionales $servicio,
        ServicioDesplieguePlanificador $despliegue,
    ): JsonResponse {
        abort_unless($planOperacional->temporada()->where('activa', true)->exists(), 404);
        $horizon = ($planOperacional->contexto ?? [])['planner_horizon']
            ?? config('planificador.horizon');
        if (! config('planificador.generacion_automatica')
            || config('planificador.mode') !== 'guided'
            || config('planificador.compute') !== 'tablet'
            || $horizon !== 'rolling') {
            throw new DomainException(
                'La frontera de tablet requiere generación automática y un plan rolling con el planificador guided/tablet.',
            );
        }
        $max = (int) config('planificador.frontier_max', 4);
        $datos = $request->validate([
            'snapshot_version' => ['required', 'string', 'size:64'],
            'planner_version' => ['required', 'string', 'max:80'],
            'propuestas' => ['required', 'array', 'min:1', "max:{$max}"],
            'propuestas.*.tarea_id' => ['required', 'uuid', 'distinct', 'exists:tareas_movimiento,id'],
            'propuestas.*.posicion_destino_id' => ['required', 'uuid', 'exists:posiciones,id'],
            'propuestas.*.tarea_version' => ['required', 'integer', 'min:1'],
            'propuestas.*.plan_version' => ['required', 'integer', 'min:1'],
            'propuestas.*.version_camara_conocida' => ['required', 'integer', 'min:0'],
            'propuestas.*.score' => ['nullable', 'numeric'],
            'propuestas.*.motivo' => ['nullable', 'string', 'max:240'],
        ]);
        $snapshot = $servicio->snapshot($planOperacional);
        if (! hash_equals($snapshot['snapshot_version'], $datos['snapshot_version'])) {
            return response()->json([
                'message' => 'El snapshot cambió antes de materializar la frontera.',
                'codigo' => 'snapshot_obsoleto',
                'data' => [
                    'snapshot_version' => $snapshot['snapshot_version'],
                    'aceptadas' => [],
                    'rechazadas' => $datos['propuestas'],
                ],
            ], 409);
        }

        [$usuario, $dispositivo] = $contexto->obtener($request);
        $aceptadas = [];
        $rechazadas = [];

        foreach ($datos['propuestas'] as $propuesta) {
            $tarea = TareaMovimiento::query()
                ->where('plan_operacional_id', $planOperacional->id)
                ->find($propuesta['tarea_id']);
            if (! $tarea) {
                $rechazadas[] = [
                    'tarea_id' => $propuesta['tarea_id'],
                    'motivo' => 'La tarea no pertenece al plan solicitado.',
                ];

                continue;
            }

            try {
                $posicion = Posicion::query()->findOrFail($propuesta['posicion_destino_id']);
                if (! $despliegue->dirige(array_filter([
                    $tarea->camara_origen_id,
                    $tarea->camara_destino_id,
                    $posicion->camara_id,
                ]))) {
                    throw new DomainException(
                        'La propuesta involucra una cámara fuera del rollout dirigido o el planificador no está habilitado completamente.',
                    );
                }
                $materializada = $servicio->materializarDestino(
                    tarea: $tarea,
                    posicion: $posicion,
                    usuario: $usuario,
                    dispositivo: $dispositivo,
                    versionTarea: (int) $propuesta['tarea_version'],
                    versionPlan: (int) $propuesta['plan_version'],
                    versionCamara: (int) $propuesta['version_camara_conocida'],
                );
                $aceptadas[] = [
                    'tarea' => (new TareaMovimientoResource($materializada))->resolve($request),
                    'score' => $propuesta['score'] ?? null,
                    'motivo' => $propuesta['motivo'] ?? null,
                    'planner_version' => $datos['planner_version'],
                ];
            } catch (ConflictoOperacion|DomainException $exception) {
                $rechazadas[] = [
                    'tarea_id' => $propuesta['tarea_id'],
                    'posicion_destino_id' => $propuesta['posicion_destino_id'],
                    'motivo' => $exception->getMessage(),
                ];
            }
        }

        return response()->json([
            'data' => [
                'aceptadas' => $aceptadas,
                'rechazadas' => $rechazadas,
                'recalcular' => $rechazadas !== [],
                'snapshot' => $servicio->snapshot($planOperacional->refresh()),
            ],
        ]);
    }

    public function iniciar(
        Request $request,
        TareaMovimiento $tareaMovimiento,
        ContextoOperacional $contexto,
        ServicioPlanesOperacionales $servicio,
        ServicioConfirmacionInicioTarea $confirmaciones,
    ): TareaMovimientoResource {
        [$usuario, $dispositivo] = $contexto->obtener($request);

        if (! $confirmaciones->exigida($tareaMovimiento)) {
            return new TareaMovimientoResource(
                $servicio->iniciar($tareaMovimiento, $usuario, $dispositivo),
            );
        }

        $datos = $request->validate([
            'confirmacion_folio' => ['required', 'string', 'max:50'],
            'pin' => ['required', 'string', 'max:10'],
        ], [
            'confirmacion_folio.required' => 'Digita los últimos 4 dígitos del folio leídos en la etiqueta.',
            'pin.required' => 'Ingresa tu PIN operacional.',
        ]);

        $confirmaciones->verificar(
            $tareaMovimiento,
            $usuario,
            $dispositivo,
            $datos['confirmacion_folio'],
            $datos['pin'],
        );
        $tarea = $servicio->iniciar($tareaMovimiento, $usuario, $dispositivo);
        $confirmaciones->registrarConfirmada($tarea, $usuario, $dispositivo, $datos['confirmacion_folio']);

        return new TareaMovimientoResource($tarea);
    }

    public function liberar(
        Request $request,
        TareaMovimiento $tareaMovimiento,
        ContextoOperacional $contexto,
        ServicioPlanesOperacionales $servicio,
    ): TareaMovimientoResource {
        [$usuario, $dispositivo] = $contexto->obtener($request);

        return new TareaMovimientoResource(
            $servicio->liberar($tareaMovimiento, $usuario, $dispositivo),
        );
    }

    public function renovar(
        Request $request,
        TareaMovimiento $tareaMovimiento,
        ContextoOperacional $contexto,
        ServicioPlanesOperacionales $servicio,
    ): TareaMovimientoResource {
        [$usuario, $dispositivo] = $contexto->obtener($request);

        return new TareaMovimientoResource(
            $servicio->renovar($tareaMovimiento, $usuario, $dispositivo),
        );
    }

    public function completarExtraccionTemporal(
        Request $request,
        TareaMovimiento $tareaMovimiento,
        ContextoOperacional $contexto,
        ServicioMovimientoEstiba $movimientos,
    ): JsonResponse {
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'sesion_origen_id' => ['required', 'uuid', 'exists:sesiones_estiba,id'],
            'version_origen_conocida' => ['required', 'integer', 'min:0'],
            'generado_dispositivo_at' => ['required', 'date'],
            'advertencias_confirmadas' => ['sometimes', 'array', 'max:5'],
            'advertencias_confirmadas.*' => ['required', 'string', 'max:100', 'distinct'],
        ]);
        $tareaMovimiento->refresh();
        if ($tareaMovimiento->tipo_paso_maniobra !== TipoPasoManiobra::ExtraccionTemporal
            || $tareaMovimiento->tipo_movimiento !== TipoMovimiento::Retiro) {
            throw new DomainException('La tarea no corresponde a una extracción temporal.');
        }

        [$usuario, $dispositivo] = $contexto->obtener($request);
        $movimiento = $movimientos->retirar(
            operacionId: $datos['operacion_id'],
            folio: Folio::query()->findOrFail($tareaMovimiento->folio_id),
            sesionOrigen: SesionEstiba::query()->findOrFail($datos['sesion_origen_id']),
            usuario: $usuario,
            dispositivo: $dispositivo,
            versionOrigenConocida: (int) $datos['version_origen_conocida'],
            generadoDispositivoAt: CarbonImmutable::parse($datos['generado_dispositivo_at']),
            motivo: 'Extracción temporal controlada por maniobra física.',
            advertenciasConfirmadas: $datos['advertencias_confirmadas'] ?? [],
            tareaMovimiento: $tareaMovimiento,
        );

        return response()->json([
            'data' => [
                'movimiento_id' => $movimiento->id,
                'maniobra_id' => $tareaMovimiento->maniobra_operacional_id,
                'estado' => 'extraido_temporalmente',
            ],
        ]);
    }

    public function reportarDiscrepancia(
        Request $request,
        TareaMovimiento $tareaMovimiento,
        ContextoOperacional $contexto,
        ServicioManiobrasOperacionales $maniobras,
    ): JsonResponse {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in([
                'pallet_no_coincide',
                'posicion_no_coincide',
                'posicion_vacia',
                'obstaculo',
                'pallet_no_movible',
                'otra',
            ])],
            'detalle' => ['nullable', 'string', 'max:500'],
        ]);
        [$usuario, $dispositivo] = $contexto->obtener($request);
        $discrepancia = $maniobras->reportarDiscrepancia(
            $tareaMovimiento,
            $usuario,
            $dispositivo,
            $datos['tipo'],
            $datos['detalle'] ?? null,
        );

        return response()->json([
            'data' => [
                'id' => $discrepancia->id,
                'estado' => $discrepancia->estado->value,
                'maniobra_id' => $discrepancia->maniobra_operacional_id,
                'tarea_id' => $discrepancia->tarea_movimiento_id,
            ],
        ], 202);
    }

    /** @return array<int, string> */
    private function relacionesTarea(): array
    {
        return [
            'planOperacional:id,temporada_id,tipo,estado,prioridad,titulo,version,contexto',
            'folio:id,numero_folio,tipo_bulto,variedad,calibre,marca,exportadora,fecha_ingreso',
            'camaraOrigen:id,nombre',
            'posicionOrigen:id,camara_id,etiqueta,banda,posicion,nivel',
            'camaraDestino:id,nombre',
            'posicionDestino:id,camara_id,etiqueta,banda,posicion,nivel',
            'responsable:id,name',
            'dispositivo:id,codigo,nombre',
            'reservaActiva:id,tarea_movimiento_id,bloqueo_tarea_id,bloqueo_posicion_id,estado,reservada_at,renovada_at,vence_at,version',
            'maniobraOperacional:id,plan_operacional_id,estado,prioridad,candidate_key,titulo,secuencia_actual,costo_movimientos,beneficio_estimado,riesgo_operacional,responsable_user_id,dispositivo_id,version,contexto',
            'maniobraOperacional.objetivos:id,tipo,estado,prioridad,titulo',
            'maniobraOperacional.ultimaDecisionArbitraje',
            'maniobraOperacional.ultimaDecisionArbitraje.ciclo:id,snapshot_version,created_at',
            ...$this->relacionesDetalleManiobra('maniobraOperacional'),
        ];
    }

    /** @return array<int, string> */
    private function relacionesDetalleManiobra(string $prefijo): array
    {
        return [
            "{$prefijo}.pasos:id,maniobra_operacional_id,secuencia_maniobra,tipo_movimiento,tipo_paso_maniobra,estado,folio_id,camara_origen_id,posicion_origen_id,camara_destino_id,posicion_destino_id,instruccion,contexto",
            "{$prefijo}.pasos.folio:id,numero_folio",
            "{$prefijo}.pasos.camaraOrigen:id,nombre",
            "{$prefijo}.pasos.posicionOrigen:id,camara_id,etiqueta,banda,posicion,nivel",
            "{$prefijo}.pasos.camaraDestino:id,nombre",
            "{$prefijo}.pasos.posicionDestino:id,camara_id,etiqueta,banda,posicion,nivel",
            "{$prefijo}.custodiasTemporales:id,maniobra_operacional_id,folio_id,camara_origen_id,posicion_origen_id,estado,extraido_at",
            "{$prefijo}.custodiasTemporales.folio:id,numero_folio",
            "{$prefijo}.custodiasTemporales.camaraOrigen:id,nombre",
            "{$prefijo}.custodiasTemporales.posicionOrigen:id,camara_id,etiqueta,banda,posicion,nivel",
        ];
    }

    private function ordenPrioridad(): string
    {
        return "CASE prioridad WHEN 'critica' THEN 1 WHEN 'urgente' THEN 2 WHEN 'alta' THEN 3 ELSE 4 END";
    }
}
