<?php

namespace App\Services\Planificador;

use App\Enums\DecisionArbitrajeManiobra;
use App\Enums\EstadoCustodiaTemporal;
use App\Enums\EstadoDiscrepanciaManiobra;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoReservaTareaMovimiento;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\TipoPasoManiobra;
use App\Models\CicloArbitrajeManiobras;
use App\Models\CustodiaTemporalManiobra;
use App\Models\DecisionArbitrajeManiobra as DecisionPersistida;
use App\Models\DiscrepanciaManiobra;
use App\Models\ManiobraOperacional;
use App\Models\Movimiento;
use App\Models\PlanOperacional;
use App\Models\ReservaTareaMovimiento;
use App\Models\TareaMovimiento;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class ServicioSaludPlanificador
{
    public function __construct(
        private readonly ServicioDesplieguePlanificador $despliegue,
        private readonly ServicioEstadoArbitrajePlanificador $estadoArbitraje,
        private readonly ServicioRecalculosPendientesPlanificador $recalculos,
        private readonly ServicioSaludProcesos $procesos,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
        ?string $camaraId = null,
    ): array {
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        $temporadaId = $temporada?->id;
        $vigenciaArbitraje = $temporada
            ? $this->estadoArbitraje->consultar($temporada)
            : null;
        if ($vigenciaArbitraje !== null) {
            unset($vigenciaArbitraje['ciclo']);
        }
        $operacion = $this->metricasOperacion($temporadaId, $desde, $hasta, $camaraId);
        $salud = $this->saludActual($temporadaId, $camaraId);
        $despliegue = $this->despliegue->resumen();
        $contenido = [
            'ventana' => [
                'desde' => $desde->toIso8601String(),
                'hasta' => $hasta->toIso8601String(),
                'camara_id' => $camaraId,
                'temporada_id' => $temporadaId,
            ],
            'despliegue' => $despliegue,
            'vigencia_arbitraje' => $vigenciaArbitraje,
            'procesos' => $this->procesos->consultar(),
            'proyecciones_pendientes' => $this->recalculos->salud(),
            'salud' => $salud,
            'metricas' => $operacion,
        ];

        return [
            'snapshot_version' => hash(
                'sha256',
                json_encode($contenido, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ),
            'generado_at' => now()->toIso8601String(),
            ...$contenido,
        ];
    }

    /** @return array<string, mixed> */
    private function metricasOperacion(
        ?string $temporadaId,
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
        ?string $camaraId,
    ): array {
        if ($temporadaId === null) {
            return $this->metricasVacias();
        }

        $planes = PlanOperacional::query()
            ->where('temporada_id', $temporadaId)
            ->whereBetween('created_at', [$desde, $hasta]);
        $this->filtrarPlanesPorCamara($planes, $camaraId);

        $tareas = TareaMovimiento::query()
            ->whereHas('planOperacional', fn (Builder $consulta) => $consulta
                ->where('temporada_id', $temporadaId))
            ->whereBetween('created_at', [$desde, $hasta]);
        $this->filtrarTareasPorCamara($tareas, $camaraId);

        $maniobras = ManiobraOperacional::query()
            ->whereHas('planOperacional', fn (Builder $consulta) => $consulta
                ->where('temporada_id', $temporadaId))
            ->whereBetween('created_at', [$desde, $hasta]);
        $this->filtrarManiobrasPorCamara($maniobras, $camaraId);

        $movimientos = Movimiento::query()
            ->whereNotNull('plan_operacional_id')
            ->whereHas('planOperacional', fn (Builder $consulta) => $consulta
                ->where('temporada_id', $temporadaId))
            ->whereBetween('created_at', [$desde, $hasta]);
        $this->filtrarMovimientosPorCamara($movimientos, $camaraId);

        $reservas = ReservaTareaMovimiento::query()
            ->whereHas('tareaMovimiento.planOperacional', fn (Builder $consulta) => $consulta
                ->where('temporada_id', $temporadaId))
            ->whereBetween('created_at', [$desde, $hasta]);
        if ($camaraId !== null) {
            $reservas->whereHas('tareaMovimiento', fn (Builder $consulta) => $this
                ->filtrarTareasPorCamara($consulta, $camaraId));
        }

        $discrepancias = DiscrepanciaManiobra::query()
            ->whereHas('maniobraOperacional.planOperacional', fn (Builder $consulta) => $consulta
                ->where('temporada_id', $temporadaId))
            ->whereBetween('created_at', [$desde, $hasta]);
        if ($camaraId !== null) {
            $discrepancias->whereHas('tareaMovimiento', fn (Builder $consulta) => $this
                ->filtrarTareasPorCamara($consulta, $camaraId));
        }

        $totalMovimientos = (clone $movimientos)->count();
        $palletsMovidos = (clone $movimientos)->distinct()->count('folio_id');
        $contextos = (clone $maniobras)->get(['estado', 'contexto']);
        $blockers = $contextos->sum(
            fn (ManiobraOperacional $maniobra): int => (int) ($maniobra->contexto['blockers'] ?? 0),
        );
        $blockersRetorno = $contextos->sum(
            fn (ManiobraOperacional $maniobra): int => (int) ($maniobra->contexto['blockers_retorno'] ?? 0),
        );
        $oportunidades = $contextos->filter(
            fn (ManiobraOperacional $maniobra): bool => $maniobra->estado === EstadoManiobraOperacional::Completada
                && (bool) ($maniobra->contexto['es_movimiento_oportunidad'] ?? false),
        )->count();

        $tareasEjecutadas = (clone $tareas)
            ->whereNotNull('iniciada_at')
            ->whereNotNull('completada_at')
            ->get(['iniciada_at', 'completada_at']);
        $planesEjecutados = (clone $planes)
            ->whereNotNull('iniciado_at')
            ->whereNotNull('completado_at')
            ->get(['iniciado_at', 'completado_at']);

        return [
            'planes' => [
                'total' => (clone $planes)->count(),
                'por_tipo' => $this->conteos($planes, 'tipo'),
                'por_estado' => $this->conteos($planes, 'estado'),
                'duracion_promedio_segundos' => $this->promedioDuracion(
                    $planesEjecutados,
                    'iniciado_at',
                    'completado_at',
                ),
            ],
            'maniobras' => [
                'total' => (clone $maniobras)->count(),
                'por_estado' => $this->conteos($maniobras, 'estado'),
                'replanificaciones' => (clone $maniobras)
                    ->where('estado', EstadoManiobraOperacional::Cancelada->value)
                    ->count(),
                'costo_movimientos_estimado' => (int) (clone $maniobras)->sum('costo_movimientos'),
                'beneficio_estimado' => (int) (clone $maniobras)->sum('beneficio_estimado'),
                'blockers' => (int) $blockers,
                'blockers_con_retorno' => (int) $blockersRetorno,
                'oportunidades_aprovechadas' => $oportunidades,
            ],
            'tareas' => [
                'total' => (clone $tareas)->count(),
                'por_estado' => $this->conteos($tareas, 'estado'),
                'extracciones_temporales' => (clone $tareas)
                    ->where('tipo_paso_maniobra', TipoPasoManiobra::ExtraccionTemporal->value)
                    ->count(),
                'retornos' => (clone $tareas)
                    ->where('tipo_paso_maniobra', TipoPasoManiobra::RetornoBanda->value)
                    ->count(),
                'duracion_promedio_segundos' => $this->promedioDuracion(
                    $tareasEjecutadas,
                    'iniciada_at',
                    'completada_at',
                ),
            ],
            'ejecucion' => [
                'movimientos' => $totalMovimientos,
                'pallets_movidos' => $palletsMovidos,
                'movimientos_por_pallet' => $palletsMovidos > 0
                    ? round($totalMovimientos / $palletsMovidos, 2)
                    : 0.0,
                'desviaciones_destino' => $this->desviacionesDestino(
                    $temporadaId,
                    $desde,
                    $hasta,
                    $camaraId,
                ),
            ],
            'reservas' => [
                'total' => (clone $reservas)->count(),
                'por_estado' => $this->conteos($reservas, 'estado'),
            ],
            'discrepancias' => [
                'total' => (clone $discrepancias)->count(),
                'por_estado' => $this->conteos($discrepancias, 'estado'),
            ],
            'arbitraje' => $this->metricasArbitraje(
                $temporadaId,
                $desde,
                $hasta,
                $camaraId,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function metricasArbitraje(
        string $temporadaId,
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
        ?string $camaraId,
    ): array {
        $ciclos = CicloArbitrajeManiobras::query()
            ->where('temporada_id', $temporadaId)
            ->whereBetween('created_at', [$desde, $hasta]);
        if ($camaraId !== null) {
            $ciclos->whereHas('decisiones', fn (Builder $consulta) => $this
                ->filtrarDecisionesPorCamara($consulta, $camaraId));
        }

        $decisiones = DecisionPersistida::query()
            ->whereHas('ciclo', fn (Builder $consulta) => $consulta
                ->where('temporada_id', $temporadaId)
                ->whereBetween('created_at', [$desde, $hasta]));
        $this->filtrarDecisionesPorCamara($decisiones, $camaraId);

        $conflictos = (clone $decisiones)
            ->where('decision', DecisionArbitrajeManiobra::ExcluidaConflicto->value)
            ->get(['maniobra_operacional_id', 'conflictos']);
        $porRecurso = [
            'folio' => 0,
            'posicion' => 0,
            'banda' => 0,
            'otro' => 0,
        ];
        foreach ($conflictos as $decision) {
            foreach ($decision->conflictos ?? [] as $conflicto) {
                $recurso = (string) ($conflicto['recurso'] ?? '');
                $tipo = str_contains($recurso, ':')
                    ? explode(':', $recurso, 2)[0]
                    : 'otro';
                $porRecurso[array_key_exists($tipo, $porRecurso) ? $tipo : 'otro']++;
            }
        }

        $ultimoCiclo = CicloArbitrajeManiobras::query()
            ->where('temporada_id', $temporadaId)
            ->whereBetween('created_at', [$desde, $hasta])
            ->latest('created_at')
            ->latest('id')
            ->first();
        $ultimo = null;
        if ($ultimoCiclo) {
            $decisionesUltimo = DecisionPersistida::query()
                ->where('ciclo_arbitraje_id', $ultimoCiclo->id);
            $this->filtrarDecisionesPorCamara($decisionesUltimo, $camaraId);
            $ultimo = [
                'id' => $ultimoCiclo->id,
                'snapshot_version' => $ultimoCiclo->snapshot_version,
                'generado_at' => $ultimoCiclo->created_at?->toIso8601String(),
                'capacidad_ejecucion' => $ultimoCiclo->capacidad_ejecucion,
                'frontera_max' => $ultimoCiclo->frontera_max,
                'por_decision' => $this->conteos($decisionesUltimo, 'decision'),
            ];
        }

        return [
            'unidad_ciclo' => 'estado_nuevo',
            'ciclos_nuevos' => (clone $ciclos)->count(),
            'decisiones_total' => (clone $decisiones)->count(),
            'maniobras_unicas' => (clone $decisiones)
                ->distinct()
                ->count('maniobra_operacional_id'),
            'por_decision' => $this->conteos($decisiones, 'decision'),
            'conflictos' => [
                'maniobras_excluidas' => $conflictos
                    ->pluck('maniobra_operacional_id')
                    ->unique()
                    ->count(),
                'recursos_involucrados' => array_sum($porRecurso),
                'por_recurso' => $porRecurso,
            ],
            'ultimo_ciclo' => $ultimo,
        ];
    }

    /** @return array{estado: string, riesgos: array<string, int>} */
    public function saludActual(?string $temporadaId, ?string $camaraId = null): array
    {
        $riesgos = $this->riesgosActuales($temporadaId, $camaraId);

        return [
            'estado' => $this->estadoSalud($riesgos),
            'riesgos' => $riesgos,
        ];
    }

    /** @return array<string, int> */
    private function riesgosActuales(?string $temporadaId, ?string $camaraId): array
    {
        if ($temporadaId === null) {
            return [
                'leases_vencidos_activos' => 0,
                'tareas_estancadas' => 0,
                'custodias_temporales_activas' => 0,
                'maniobras_completadas_con_custodia' => 0,
                'discrepancias_abiertas' => 0,
            ];
        }

        $tareas = TareaMovimiento::query()
            ->whereHas('planOperacional', fn (Builder $consulta) => $consulta
                ->where('temporada_id', $temporadaId));
        $this->filtrarTareasPorCamara($tareas, $camaraId);

        $reservas = ReservaTareaMovimiento::query()
            ->where('estado', EstadoReservaTareaMovimiento::Activa->value)
            ->where('vence_at', '<', now())
            ->whereHas('tareaMovimiento.planOperacional', fn (Builder $consulta) => $consulta
                ->where('temporada_id', $temporadaId));
        if ($camaraId !== null) {
            $reservas->whereHas('tareaMovimiento', fn (Builder $consulta) => $this
                ->filtrarTareasPorCamara($consulta, $camaraId));
        }

        $custodias = CustodiaTemporalManiobra::query()
            ->where('estado', EstadoCustodiaTemporal::Activa->value)
            ->whereHas('maniobraOperacional.planOperacional', fn (Builder $consulta) => $consulta
                ->where('temporada_id', $temporadaId));
        if ($camaraId !== null) {
            $custodias->where('camara_origen_id', $camaraId);
        }

        $discrepancias = DiscrepanciaManiobra::query()
            ->where('estado', EstadoDiscrepanciaManiobra::Abierta->value)
            ->whereHas('maniobraOperacional.planOperacional', fn (Builder $consulta) => $consulta
                ->where('temporada_id', $temporadaId));
        if ($camaraId !== null) {
            $discrepancias->whereHas('tareaMovimiento', fn (Builder $consulta) => $this
                ->filtrarTareasPorCamara($consulta, $camaraId));
        }

        return [
            'leases_vencidos_activos' => $reservas->count(),
            'tareas_estancadas' => (clone $tareas)
                ->where('estado', EstadoTareaMovimiento::EnProceso->value)
                ->where('iniciada_at', '<=', now()->subMinutes(
                    (int) config('planificador.tarea_estancada_minutos', 30),
                ))
                ->count(),
            'custodias_temporales_activas' => (clone $custodias)->count(),
            'maniobras_completadas_con_custodia' => (clone $custodias)
                ->whereHas('maniobraOperacional', fn (Builder $consulta) => $consulta
                    ->where('estado', EstadoManiobraOperacional::Completada->value))
                ->count(),
            'discrepancias_abiertas' => $discrepancias->count(),
        ];
    }

    /** @param array<string, int> $riesgos */
    private function estadoSalud(array $riesgos): string
    {
        if ($riesgos['leases_vencidos_activos'] > 0
            || $riesgos['maniobras_completadas_con_custodia'] > 0) {
            return 'critico';
        }
        if ($riesgos['tareas_estancadas'] > 0
            || $riesgos['custodias_temporales_activas'] > 0
            || $riesgos['discrepancias_abiertas'] > 0) {
            return 'advertencia';
        }

        return 'saludable';
    }

    /** @return array<string, mixed> */
    private function metricasVacias(): array
    {
        return [
            'planes' => ['total' => 0, 'por_tipo' => [], 'por_estado' => [], 'duracion_promedio_segundos' => null],
            'maniobras' => [
                'total' => 0,
                'por_estado' => [],
                'replanificaciones' => 0,
                'costo_movimientos_estimado' => 0,
                'beneficio_estimado' => 0,
                'blockers' => 0,
                'blockers_con_retorno' => 0,
                'oportunidades_aprovechadas' => 0,
            ],
            'tareas' => [
                'total' => 0,
                'por_estado' => [],
                'extracciones_temporales' => 0,
                'retornos' => 0,
                'duracion_promedio_segundos' => null,
            ],
            'ejecucion' => [
                'movimientos' => 0,
                'pallets_movidos' => 0,
                'movimientos_por_pallet' => 0.0,
                'desviaciones_destino' => 0,
            ],
            'reservas' => ['total' => 0, 'por_estado' => []],
            'discrepancias' => ['total' => 0, 'por_estado' => []],
            'arbitraje' => [
                'unidad_ciclo' => 'estado_nuevo',
                'ciclos_nuevos' => 0,
                'decisiones_total' => 0,
                'maniobras_unicas' => 0,
                'por_decision' => [],
                'conflictos' => [
                    'maniobras_excluidas' => 0,
                    'recursos_involucrados' => 0,
                    'por_recurso' => [
                        'folio' => 0,
                        'posicion' => 0,
                        'banda' => 0,
                        'otro' => 0,
                    ],
                ],
                'ultimo_ciclo' => null,
            ],
        ];
    }

    /** @return array<string, int> */
    private function conteos(Builder $consulta, string $campo): array
    {
        return (clone $consulta)
            ->selectRaw("{$campo}, COUNT(*) as total")
            ->groupBy($campo)
            ->orderBy($campo)
            ->pluck('total', $campo)
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    private function promedioDuracion($modelos, string $inicio, string $fin): ?int
    {
        if ($modelos->isEmpty()) {
            return null;
        }

        return (int) round($modelos->avg(
            fn ($modelo): int => $modelo->{$inicio}->diffInSeconds($modelo->{$fin}),
        ));
    }

    private function desviacionesDestino(
        string $temporadaId,
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
        ?string $camaraId,
    ): int {
        $consulta = Movimiento::query()
            ->join('tareas_movimiento', 'tareas_movimiento.id', '=', 'movimientos.tarea_movimiento_id')
            ->join('planes_operacionales', 'planes_operacionales.id', '=', 'movimientos.plan_operacional_id')
            ->where('planes_operacionales.temporada_id', $temporadaId)
            ->whereBetween('movimientos.created_at', [$desde, $hasta])
            ->whereNotNull('tareas_movimiento.posicion_destino_id')
            ->whereNotNull('movimientos.posicion_destino_id')
            ->whereColumn('tareas_movimiento.posicion_destino_id', '!=', 'movimientos.posicion_destino_id');
        if ($camaraId !== null) {
            $consulta->where(function (Builder $filtro) use ($camaraId): void {
                $filtro->where('movimientos.camara_origen_id', $camaraId)
                    ->orWhere('movimientos.camara_destino_id', $camaraId);
            });
        }

        return $consulta->count();
    }

    private function filtrarPlanesPorCamara(Builder $consulta, ?string $camaraId): void
    {
        if ($camaraId === null) {
            return;
        }

        $consulta->where(function (Builder $filtro) use ($camaraId): void {
            $filtro->whereHas('tareas', fn (Builder $tareas) => $this
                ->filtrarTareasPorCamara($tareas, $camaraId))
                ->orWhere(function (Builder $directos) use ($camaraId): void {
                    $directos->whereIn('referencia_tipo', [
                        'camara_reordenamiento',
                        'camara_desocupacion',
                        'camara_emergencia',
                    ])->where('referencia_id', $camaraId);
                });
        });
    }

    private function filtrarTareasPorCamara(Builder $consulta, ?string $camaraId): void
    {
        if ($camaraId === null) {
            return;
        }

        $consulta->where(function (Builder $filtro) use ($camaraId): void {
            $filtro->where('camara_origen_id', $camaraId)
                ->orWhere('camara_destino_id', $camaraId);
        });
    }

    private function filtrarManiobrasPorCamara(Builder $consulta, ?string $camaraId): void
    {
        if ($camaraId !== null) {
            $consulta->where(function (Builder $filtro) use ($camaraId): void {
                $filtro->whereHas('pasos', fn (Builder $pasos) => $this
                    ->filtrarTareasPorCamara($pasos, $camaraId))
                    ->orWhereHas(
                        'reservasBandas',
                        fn (Builder $reservas) => $reservas->where('camara_id', $camaraId),
                    )
                    ->orWhereHas(
                        'custodiasTemporales',
                        fn (Builder $custodias) => $custodias->where(
                            'camara_origen_id',
                            $camaraId,
                        ),
                    );
            });
        }
    }

    private function filtrarDecisionesPorCamara(Builder $consulta, ?string $camaraId): void
    {
        if ($camaraId !== null) {
            $consulta->whereHas('maniobraOperacional', fn (Builder $maniobras) => $this
                ->filtrarManiobrasPorCamara($maniobras, $camaraId));
        }
    }

    private function filtrarMovimientosPorCamara(Builder $consulta, ?string $camaraId): void
    {
        if ($camaraId !== null) {
            $consulta->where(function (Builder $filtro) use ($camaraId): void {
                $filtro->where('camara_origen_id', $camaraId)
                    ->orWhere('camara_destino_id', $camaraId);
            });
        }
    }
}
