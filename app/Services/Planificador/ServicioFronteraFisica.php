<?php

namespace App\Services\Planificador;

use App\Enums\ContenidoCamara;
use App\Enums\DecisionArbitrajeManiobra;
use App\Enums\EstadoCamara;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\TipoMovimiento;
use App\Models\Camara;
use App\Models\CicloArbitrajeManiobras;
use App\Models\Dispositivo;
use App\Models\ReservaTareaMovimiento;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioFronteraFisica
{
    private const VERSION_SNAPSHOT = 'frontera_fisica_global_v2_preferencia_despacho';

    public function __construct(
        private readonly ServicioEstadoArbitrajePlanificador $estadoArbitraje,
        private readonly ServicioDesplieguePlanificador $despliegue,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(User $usuario, Dispositivo $dispositivo): array
    {
        return DB::transaction(function () use ($usuario, $dispositivo): array {
            $temporada = Temporada::query()
                ->where('activa', true)
                ->first();
            if (! $temporada) {
                throw new DomainException('No existe una temporada operacional activa.');
            }

            $camarasDirigidas = $this->despliegue->idsCamarasDirigidas();
            $proyeccionArbitraje = $this->estadoArbitraje->consultar($temporada);
            $ultimoCiclo = $proyeccionArbitraje['ciclo'];
            $ciclo = $proyeccionArbitraje['vigente'] ? $ultimoCiclo : null;
            unset($proyeccionArbitraje['ciclo']);
            $decisiones = $ciclo?->decisiones?->keyBy('maniobra_operacional_id') ?? collect();
            $tareas = $this->tareasActuales($temporada, $usuario, $dispositivo)
                ->map(function (TareaMovimiento $tarea) use (
                    $camarasDirigidas,
                    $decisiones,
                ): array {
                    $maniobra = $tarea->maniobraOperacional;
                    $reserva = $tarea->reservaActiva;
                    $decision = $maniobra
                        ? $decisiones->get($maniobra->id)
                        : null;
                    $horizon = ($tarea->planOperacional->contexto ?? [])['planner_horizon']
                        ?? config('planificador.horizon');
                    $materializable = $tarea->estado === EstadoTareaMovimiento::Asumida
                        && $tarea->tipo_movimiento !== TipoMovimiento::Retiro
                        && $reserva?->bloqueo_posicion_id === null
                        && $maniobra !== null
                        && $maniobra->estado === EstadoManiobraOperacional::EnEjecucion
                        && $horizon === 'rolling'
                        && $this->tareaEnRollout($tarea, $camarasDirigidas)
                        && ($decision?->decision->materializable()
                            || $decision?->decision === DecisionArbitrajeManiobra::FueraPlanificador);

                    return [
                        'id' => $tarea->id,
                        'version' => $tarea->version,
                        'estado' => $tarea->estado->value,
                        'tipo_movimiento' => $tarea->tipo_movimiento->value,
                        'tipo_paso_maniobra' => $tarea->tipo_paso_maniobra?->value,
                        'punto_no_retorno' => $tarea->estado === EstadoTareaMovimiento::EnProceso,
                        'folio_id' => $tarea->folio_id,
                        'camara_origen_id' => $tarea->camara_origen_id,
                        'posicion_origen_id' => $tarea->posicion_origen_id,
                        'camara_destino_id' => $tarea->camara_destino_id,
                        'posicion_destino_id' => $tarea->posicion_destino_id,
                        'plan_id' => $tarea->plan_operacional_id,
                        'plan_version' => $tarea->planOperacional->version,
                        'horizon' => $horizon,
                        'maniobra_id' => $maniobra?->id,
                        'maniobra_version' => $maniobra?->version,
                        'secuencia_maniobra' => $tarea->secuencia_maniobra,
                        'secuencia_actual' => $maniobra?->secuencia_actual,
                        'decision_arbitraje' => $decision?->decision->value,
                        'orden_arbitraje' => $decision?->orden,
                        'reserva' => $reserva ? [
                            'id' => $reserva->id,
                            'version' => $reserva->version,
                            'destino_reservado' => $reserva->bloqueo_posicion_id !== null,
                            'posicion_destino_id' => $reserva->bloqueo_posicion_id,
                        ] : null,
                        'destino_reservado' => $reserva?->bloqueo_posicion_id !== null,
                        'materializable' => $materializable,
                    ];
                })
                ->sortBy(fn (array $tarea): string => implode(':', [
                    str_pad((string) ($tarea['orden_arbitraje'] ?? 999999), 6, '0', STR_PAD_LEFT),
                    $tarea['maniobra_id'] ?? '',
                    str_pad((string) ($tarea['secuencia_maniobra'] ?? 0), 6, '0', STR_PAD_LEFT),
                ]))
                ->values();
            $camaras = Camara::query()
                ->where('contenido', ContenidoCamara::Productos->value)
                ->where('estado', EstadoCamara::Activa->value)
                ->when(
                    $camarasDirigidas !== null,
                    fn ($consulta) => $consulta->whereIn('id', $camarasDirigidas),
                )
                ->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre', 'version_plano', 'revision_reservas'])
                ->map(fn (Camara $camara): array => [
                    'id' => $camara->id,
                    'codigo' => $camara->codigo,
                    'nombre' => $camara->nombre,
                    'version_plano' => $camara->version_plano,
                    'revision_reservas' => $camara->revision_reservas,
                ])
                ->values();
            $camaraPreferente = $camaras->first(fn (array $camara): bool => $this->despliegue
                ->camaraPreferenteDespacho($camara['id'], $camara['codigo']));
            $camaraPreferenteId = $camaraPreferente['id'] ?? null;
            $reservasFisicas = ReservaTareaMovimiento::query()
                ->whereNotNull('bloqueo_posicion_id')
                ->whereHas(
                    'tareaMovimiento.planOperacional',
                    fn ($consulta) => $consulta->where('temporada_id', $temporada->id),
                )
                ->count();
            $estado = [
                'version_reglas' => self::VERSION_SNAPSHOT,
                'temporada' => [$temporada->id, $temporada->updated_at?->getTimestamp()],
                'actor' => [$usuario->id, $dispositivo->id],
                'arbitraje' => [
                    $ciclo?->id,
                    $ciclo?->snapshot_version,
                    $proyeccionArbitraje['version_solicitada'],
                    $proyeccionArbitraje['version_calculada'],
                ],
                'planner' => [
                    config('planificador.mode'),
                    config('planificador.compute'),
                    config('planificador.horizon'),
                    config('planificador.frontier_max'),
                    config('planificador.maniobras_simultaneas_max'),
                    config('planificador.rollout_camaras', []),
                    $camarasDirigidas,
                    $camaraPreferenteId,
                ],
                'camaras' => $camaras->all(),
                'tareas' => $tareas->all(),
            ];

            return [
                'snapshot_version' => hash(
                    'sha256',
                    json_encode($estado, JSON_THROW_ON_ERROR),
                ),
                'generado_at' => now()->toIso8601String(),
                'planner' => [
                    'mode' => config('planificador.mode'),
                    'compute' => config('planificador.compute'),
                    'horizon' => config('planificador.horizon'),
                    'frontier_max' => config('planificador.frontier_max'),
                    'maniobras_simultaneas_max' => config(
                        'planificador.maniobras_simultaneas_max',
                    ),
                    'rollout_limitado' => config('planificador.rollout_camaras', []) !== [],
                    'camaras_dirigidas' => $camarasDirigidas,
                    'camara_preferente_despacho_id' => $camaraPreferenteId,
                ],
                'arbitraje' => $this->resumenArbitraje(
                    $ultimoCiclo,
                    $proyeccionArbitraje,
                ),
                'frontera' => [
                    'reservas_fisicas_activas' => $reservasFisicas,
                    'tareas_materializables' => $tareas
                        ->where('materializable', true)
                        ->count(),
                    'solo_paso_actual' => true,
                ],
                'camaras' => $camaras,
                'tareas' => $tareas,
            ];
        }, attempts: 3);
    }

    /** @return Collection<int, string> */
    public function idsMaterializables(array $snapshot): Collection
    {
        return collect($snapshot['tareas'] ?? [])
            ->where('materializable', true)
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->values();
    }

    public function validarPropuestaDirigida(
        TareaMovimiento $tarea,
        string $camaraDestinoId,
    ): void {
        $camaras = array_values(array_filter([
            $tarea->camara_origen_id,
            $tarea->camara_destino_id,
            $camaraDestinoId,
        ]));

        if (! $this->despliegue->dirige($camaras)) {
            throw new DomainException(
                'La propuesta involucra una cámara fuera del rollout dirigido o el planificador no está habilitado completamente.',
            );
        }
    }

    /** @param  array<int, string>|null  $camarasDirigidas */
    private function tareaEnRollout(
        TareaMovimiento $tarea,
        ?array $camarasDirigidas,
    ): bool {
        if ($camarasDirigidas === null) {
            return true;
        }
        if ($camarasDirigidas === []) {
            return false;
        }

        return collect([
            $tarea->camara_origen_id,
            $tarea->camara_destino_id,
        ])->filter()->every(
            fn (string $id): bool => in_array($id, $camarasDirigidas, true),
        );
    }

    /** @return Collection<int, TareaMovimiento> */
    private function tareasActuales(
        Temporada $temporada,
        User $usuario,
        Dispositivo $dispositivo,
    ): Collection {
        return TareaMovimiento::query()
            ->where('responsable_user_id', $usuario->id)
            ->where('dispositivo_id', $dispositivo->id)
            ->whereNotNull('maniobra_operacional_id')
            ->whereIn('estado', [
                EstadoTareaMovimiento::Asumida->value,
                EstadoTareaMovimiento::EnProceso->value,
            ])
            ->whereHas(
                'planOperacional',
                fn ($consulta) => $consulta->where('temporada_id', $temporada->id),
            )
            ->with([
                'planOperacional:id,temporada_id,tipo,estado,version,contexto',
                'maniobraOperacional:id,plan_operacional_id,estado,secuencia_actual,version',
                'reservaActiva:id,tarea_movimiento_id,bloqueo_tarea_id,bloqueo_posicion_id,version',
            ])
            ->get()
            ->filter(fn (TareaMovimiento $tarea): bool => $tarea->maniobraOperacional !== null
                && $tarea->secuencia_maniobra === $tarea->maniobraOperacional->secuencia_actual)
            ->values();
    }

    /** @return array<string, mixed> */
    private function resumenArbitraje(
        ?CicloArbitrajeManiobras $ciclo,
        array $vigencia,
    ): array {
        return [
            'ciclo_id' => $ciclo?->id,
            'snapshot_version' => $ciclo?->snapshot_version,
            'capacidad_ejecucion' => $ciclo?->capacidad_ejecucion,
            'frontera_max' => $ciclo?->frontera_max,
            'vigencia' => $vigencia,
            'en_ejecucion' => $ciclo?->decisiones?->where(
                'decision',
                DecisionArbitrajeManiobra::EnEjecucion,
            )?->count() ?? 0,
            'seleccionadas' => $ciclo?->decisiones?->where(
                'decision',
                DecisionArbitrajeManiobra::Seleccionada,
            )?->count() ?? 0,
            'alternativas' => $ciclo?->decisiones?->where(
                'decision',
                DecisionArbitrajeManiobra::Alternativa,
            )?->count() ?? 0,
            'fuera_rollout' => $ciclo?->decisiones?->where(
                'decision',
                DecisionArbitrajeManiobra::FueraRollout,
            )?->count() ?? 0,
        ];
    }
}
