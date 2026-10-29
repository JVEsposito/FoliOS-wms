<?php

namespace App\Services\Retenciones;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoPosicion;
use App\Enums\EstadoRetencionOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\ModoBandaOperacional;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPasoManiobra;
use App\Enums\TipoPlanOperacional;
use App\Enums\UsoBandaOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\BandaOperacional;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\RetencionOperacionalFolio;
use App\Models\TareaMovimiento;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Estiba\ServicioManiobrasOperacionales;
use App\Services\Planificador\ServicioDesplieguePlanificador;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioPlanSegregacionRetenidos
{
    public const REFERENCIA = 'retencion_operacional';

    public function __construct(
        private readonly ServicioManiobrasOperacionales $maniobras,
        private readonly ServicioDesplieguePlanificador $despliegue,
    ) {}

    public function sincronizar(
        RetencionOperacionalFolio $retencion,
        User $usuario,
    ): ?PlanOperacional {
        return DB::transaction(function () use ($retencion, $usuario): ?PlanOperacional {
            $retencion = RetencionOperacionalFolio::query()
                ->with('folio.ubicacionActual.posicion')
                ->lockForUpdate()
                ->findOrFail($retencion->id);
            $folio = $retencion->folio;
            $plan = $this->planExistente($retencion->id, bloquear: true);
            $camaraOrigenId = $folio->ubicacionActual?->posicion?->camara_id;

            if ($retencion->estado !== EstadoRetencionOperacional::Activa
                || $retencion->bloqueo_folio_id === null
                || ! $folio->activo
                || $folio->tipo_bulto !== TipoBulto::Pallet
                || $folio->habilitacion_almacenamiento
                    !== HabilitacionAlmacenamientoFolio::Retenido) {
                if ($plan) {
                    $this->cancelar(
                        $retencion,
                        $usuario,
                        'El folio ya no requiere segregación por retención.',
                    );
                }

                return $plan?->refresh();
            }

            if (! $this->planificadorDirigidoActivo([$camaraOrigenId])) {
                if ($plan) {
                    $this->cancelarManiobrasReversibles(
                        $plan,
                        $usuario,
                        'Planificador no dirigido: se retiró la segregación reversible.',
                    );
                }

                return $plan?->refresh();
            }

            $segregado = $folio->ubicacionActual?->posicion
                ? $this->bandaAdmiteRetenidos($folio->ubicacionActual->posicion)
                : false;
            $plan = $this->asegurarPlan($plan, $retencion, $usuario);
            $maniobraActiva = $plan->maniobras()
                ->whereIn('estado', [
                    EstadoManiobraOperacional::Pendiente->value,
                    EstadoManiobraOperacional::EnEjecucion->value,
                    EstadoManiobraOperacional::PausadaDiscrepancia->value,
                ])
                ->lockForUpdate()
                ->first();

            if ($maniobraActiva
                && in_array($maniobraActiva->estado, [
                    EstadoManiobraOperacional::EnEjecucion,
                    EstadoManiobraOperacional::PausadaDiscrepancia,
                ], true)) {
                $this->actualizarContexto($plan, $folio, $segregado, [
                    'estado_capacidad' => 'en_ejecucion',
                ]);

                return $plan->refresh();
            }

            $tareaObjetivoAjena = TareaMovimiento::query()
                ->where('folio_id', $folio->id)
                ->where('plan_operacional_id', '!=', $plan->id)
                ->whereIn('estado', $this->estadosActivosTarea())
                ->lockForUpdate()
                ->first();
            if ($tareaObjetivoAjena) {
                if ($maniobraActiva) {
                    $this->maniobras->cancelarReversible(
                        $maniobraActiva,
                        $usuario,
                        'El pallet retenido todavía posee una labor física previa en curso.',
                    );
                    $maniobraActiva = null;
                }
                $this->actualizarContexto($plan, $folio, false, [
                    'estado_capacidad' => 'pendiente',
                    'motivo_pendiente' => 'tarea_fisica_previa_en_curso',
                    'tarea_previa_id' => $tareaObjetivoAjena->id,
                ]);

                return $plan->refresh();
            }

            if ($segregado) {
                if ($maniobraActiva) {
                    $this->maniobras->cancelarReversible(
                        $maniobraActiva,
                        $usuario,
                        'El pallet ya se encuentra segregado.',
                    );
                }
                $this->actualizarContexto($plan, $folio, true, [
                    'estado_capacidad' => 'segregado',
                    'blockers' => 0,
                ]);
                $this->completar($plan, $usuario);

                return $plan->refresh();
            }

            $destino = $this->destinoDisponible($folio, $plan);
            if (! $destino) {
                if ($maniobraActiva) {
                    $this->maniobras->cancelarReversible(
                        $maniobraActiva,
                        $usuario,
                        'La capacidad de la banda de retenidos cambió.',
                    );
                }
                $this->actualizarContexto($plan, $folio, false, [
                    'estado_capacidad' => 'pendiente',
                    'motivo_pendiente' => 'sin_posicion_retenidos_disponible',
                ]);

                return $plan->refresh();
            }
            if (! $this->planificadorDirigidoActivo([$camaraOrigenId, $destino->camara_id])) {
                if ($maniobraActiva) {
                    $this->maniobras->cancelarReversible(
                        $maniobraActiva,
                        $usuario,
                        'La ruta de segregación sale del rollout guided habilitado.',
                    );
                }
                $this->actualizarContexto($plan, $folio, false, [
                    'estado_capacidad' => 'shadow',
                    'motivo_pendiente' => 'camara_fuera_rollout_guided',
                    'camara_destino_id' => $destino->camara_id,
                ]);

                return $plan->refresh();
            }

            $bloqueadores = $this->bloqueadores($folio);
            $bloqueadorInvalido = $bloqueadores->first(
                fn (UbicacionActual $ubicacion): bool => ! $ubicacion->folio?->activo
                    || $ubicacion->folio->tipo_bulto !== TipoBulto::Pallet
                    || TareaMovimiento::query()
                        ->where('folio_id', $ubicacion->folio_id)
                        ->where('plan_operacional_id', '!=', $plan->id)
                        ->whereIn('estado', $this->estadosActivosTarea())
                        ->exists(),
            );
            if ($bloqueadorInvalido) {
                if ($maniobraActiva) {
                    $this->maniobras->cancelarReversible(
                        $maniobraActiva,
                        $usuario,
                        'La ruta física de segregación cambió.',
                    );
                }
                $this->actualizarContexto($plan, $folio, false, [
                    'estado_capacidad' => 'pendiente',
                    'motivo_pendiente' => 'blocker_no_resoluble',
                    'blockers' => $bloqueadores->count(),
                ]);

                return $plan->refresh();
            }

            $candidato = $this->candidato($retencion, $folio, $destino, $bloqueadores);
            if ($maniobraActiva?->candidate_key === $candidato['candidate_key']) {
                return $plan->refresh();
            }
            if ($maniobraActiva) {
                $this->maniobras->cancelarReversible(
                    $maniobraActiva,
                    $usuario,
                    'La geometría o la capacidad de segregación cambió.',
                );
            }
            $this->maniobras->crearCerrada($plan, $usuario, $candidato);
            $this->actualizarContexto($plan, $folio, false, [
                'estado_capacidad' => 'preparada',
                'camara_destino_id' => $destino->camara_id,
                'posicion_destino_id' => $destino->id,
                'blockers' => $bloqueadores->count(),
                'movimientos_totales' => count($candidato['pasos']),
            ]);

            return $plan->refresh();
        }, attempts: 3);
    }

    public function cancelar(
        RetencionOperacionalFolio $retencion,
        User $usuario,
        string $motivo,
    ): void {
        $plan = $this->planExistente($retencion->id, bloquear: true);
        if (! $plan || $plan->estado->esFinal()) {
            return;
        }

        if (! $this->cancelarManiobrasReversibles($plan, $usuario, $motivo)) {
            throw new ConflictoOperacion(
                'La segregación ya modificó la realidad física; termine la maniobra antes de liberar la retención.',
            );
        }
        $plan->update([
            'estado' => EstadoPlanOperacional::Cancelado,
            'cancelado_por_user_id' => $usuario->id,
            'cancelado_at' => now(),
            'motivo_cancelacion' => $motivo,
            'version' => $plan->version + 1,
        ]);
    }

    /** @return Collection<int, UbicacionActual> */
    private function bloqueadores(Folio $folio): Collection
    {
        $origen = $folio->ubicacionActual?->posicion;
        if (! $origen) {
            return collect();
        }

        return UbicacionActual::query()
            ->where('folio_id', '!=', $folio->id)
            ->whereHas('posicion', fn ($consulta) => $consulta
                ->where('camara_id', $origen->camara_id)
                ->where('banda', $origen->banda)
                ->where('nivel', $origen->nivel)
                ->where('posicion', '>', $origen->posicion))
            ->with(['folio', 'posicion'])
            ->get()
            ->sortByDesc(fn (UbicacionActual $ubicacion): int => $ubicacion->posicion->posicion)
            ->values();
    }

    private function destinoDisponible(Folio $folio, PlanOperacional $plan): ?Posicion
    {
        $bandas = BandaOperacional::query()
            ->where('modo', ModoBandaOperacional::Operativa->value)
            ->whereHas('camara', fn ($consulta) => $consulta
                ->where('estado', EstadoCamara::Activa->value)
                ->where('contenido', ContenidoCamara::Productos->value))
            ->get()
            ->filter(fn (BandaOperacional $banda): bool => in_array(
                UsoBandaOperacional::Retenidos->value,
                $banda->usos_permitidos ?? [],
                true,
            ));
        $claves = $bandas
            ->map(fn (BandaOperacional $banda): string => "{$banda->camara_id}:{$banda->numero}")
            ->flip();
        $origen = $folio->ubicacionActual?->posicion;

        return Posicion::query()
            ->where('estado', EstadoPosicion::Activa->value)
            ->whereDoesntHave('ubicacionActual')
            ->whereDoesntHave('reservaTareaActiva')
            ->whereDoesntHave('reservaPreparacionSagActiva')
            ->whereDoesntHave('tareasMovimientoDestino', fn ($consulta) => $consulta
                ->where('plan_operacional_id', '!=', $plan->id)
                ->whereIn('estado', $this->estadosActivosTarea()))
            ->with('camara:id,codigo')
            ->get()
            ->filter(fn (Posicion $posicion): bool => $claves->has(
                "{$posicion->camara_id}:{$posicion->banda}",
            ))
            ->sortBy(fn (Posicion $posicion): string => sprintf(
                '%01d:%s:%05d:%05d:%05d',
                $origen?->camara_id === $posicion->camara_id ? 0 : 1,
                $posicion->camara?->codigo ?? '',
                $posicion->nivel,
                $posicion->banda,
                $posicion->posicion,
            ))
            ->first();
    }

    private function bandaAdmiteRetenidos(Posicion $posicion): bool
    {
        $banda = BandaOperacional::query()
            ->where('camara_id', $posicion->camara_id)
            ->where('numero', $posicion->banda)
            ->where('modo', ModoBandaOperacional::Operativa->value)
            ->first();

        return $banda !== null
            && in_array(
                UsoBandaOperacional::Retenidos->value,
                $banda->usos_permitidos ?? [],
                true,
            );
    }

    /**
     * @param  Collection<int, UbicacionActual>  $bloqueadores
     * @return array<string, mixed>
     */
    private function candidato(
        RetencionOperacionalFolio $retencion,
        Folio $folio,
        Posicion $destino,
        Collection $bloqueadores,
    ): array {
        $origen = $folio->ubicacionActual?->posicion;
        $pasos = [];

        foreach ($bloqueadores as $bloqueador) {
            $posicion = $bloqueador->posicion;
            $pasos[] = [
                'folio_id' => $bloqueador->folio_id,
                'tipo_movimiento' => TipoMovimiento::Retiro,
                'tipo_paso_maniobra' => TipoPasoManiobra::ExtraccionTemporal,
                'camara_origen_id' => $posicion->camara_id,
                'posicion_origen_id' => $posicion->id,
                'camara_destino_id' => null,
                'posicion_destino_id' => null,
                'instruccion' => "Retirar temporalmente {$bloqueador->folio->numero_folio}; continúe la maniobra completa.",
                'contexto' => [
                    'tipo_decision' => 'extraccion_temporal_retencion',
                    'retencion_id' => $retencion->id,
                    'camara_retorno_id' => $posicion->camara_id,
                    'banda_retorno' => $posicion->banda,
                    'nivel_retorno' => $posicion->nivel,
                    'posicion_original' => $posicion->posicion,
                ],
            ];
        }

        $tipo = ! $origen
            ? TipoMovimiento::UbicacionInicial
            : ($origen->camara_id === $destino->camara_id
                ? TipoMovimiento::Reubicacion
                : TipoMovimiento::TrasladoEntreCamaras);
        $pasos[] = [
            'folio_id' => $folio->id,
            'tipo_movimiento' => $tipo,
            'tipo_paso_maniobra' => TipoPasoManiobra::MovimientoPermanente,
            'camara_origen_id' => $origen?->camara_id,
            'posicion_origen_id' => $origen?->id,
            'camara_destino_id' => $destino->camara_id,
            'posicion_destino_id' => $destino->id,
            'instruccion' => "Segregar {$folio->numero_folio} en la banda de retenidos.",
            'contexto' => [
                'tipo_decision' => 'segregar_retenido',
                'retencion_id' => $retencion->id,
                'folio_objetivo_retenido' => true,
                'uso_banda_destino' => UsoBandaOperacional::Retenidos->value,
                'destino_precalculado_inmutable' => true,
            ],
        ];

        foreach ($bloqueadores->reverse()->values() as $bloqueador) {
            $posicion = $bloqueador->posicion;
            $pasos[] = [
                'folio_id' => $bloqueador->folio_id,
                'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                'tipo_paso_maniobra' => TipoPasoManiobra::RetornoBanda,
                'camara_origen_id' => null,
                'posicion_origen_id' => null,
                'camara_destino_id' => $posicion->camara_id,
                'posicion_destino_id' => $posicion->id,
                'instruccion' => "Devolver {$bloqueador->folio->numero_folio} a su posición protegida.",
                'contexto' => [
                    'tipo_decision' => 'retorno_blocker_retencion',
                    'retencion_id' => $retencion->id,
                    'camara_retorno_id' => $posicion->camara_id,
                    'banda_retorno' => $posicion->banda,
                    'nivel_retorno' => $posicion->nivel,
                    'profundidad_resultante' => $posicion->posicion,
                    'uso_banda_destino' => UsoBandaOperacional::TransitoProductoTerminado->value,
                    'destino_precalculado_inmutable' => true,
                ],
            ];
        }

        $geometria = hash('sha256', json_encode([
            'retencion' => $retencion->id,
            'origen' => $origen?->id,
            'destino' => $destino->id,
            'blockers' => $bloqueadores->pluck('folio_id')->all(),
        ], JSON_THROW_ON_ERROR));
        $bloqueos = $bloqueadores->isEmpty()
            ? []
            : collect([
                [
                    'camara_id' => $origen->camara_id,
                    'banda' => $origen->banda,
                    'nivel' => $origen->nivel,
                ],
                [
                    'camara_id' => $destino->camara_id,
                    'banda' => $destino->banda,
                    'nivel' => $destino->nivel,
                ],
            ])->unique(fn (array $item): string => implode(':', $item))->values()->all();

        return [
            'candidate_key' => 'segregar_retenido:'.$geometria,
            'titulo' => "Segregar {$folio->numero_folio}",
            'motivo' => $retencion->motivo,
            'beneficio_estimado' => 10000,
            'riesgo_operacional' => $bloqueadores->count() * 25,
            'contexto' => [
                'tipo_objetivo' => TipoPlanOperacional::SegregacionRetenido->value,
                'retencion_id' => $retencion->id,
                'folio_objetivo_id' => $folio->id,
                'blockers' => $bloqueadores->count(),
                'blockers_retorno' => $bloqueadores->count(),
                'movimientos_totales' => count($pasos),
                'cerrable' => true,
                'geometry_hash' => $geometria,
            ],
            'bloqueos_banda' => $bloqueos,
            'pasos' => $pasos,
        ];
    }

    private function asegurarPlan(
        ?PlanOperacional $plan,
        RetencionOperacionalFolio $retencion,
        User $usuario,
    ): PlanOperacional {
        if (! $plan) {
            return PlanOperacional::create([
                'temporada_id' => $retencion->folio->temporada_id,
                'tipo' => TipoPlanOperacional::SegregacionRetenido,
                'estado' => EstadoPlanOperacional::Programado,
                'prioridad' => PrioridadOperacional::Critica,
                'titulo' => "Segregar {$retencion->folio->numero_folio}",
                'motivo' => $retencion->motivo,
                'referencia_tipo' => self::REFERENCIA,
                'referencia_id' => $retencion->id,
                'contexto' => $this->contextoBase($retencion->folio),
                'creado_por_user_id' => $usuario->id,
                'programado_at' => now(),
            ]);
        }

        if ($plan->estado->esFinal()) {
            $plan->update([
                'estado' => EstadoPlanOperacional::Programado,
                'prioridad' => PrioridadOperacional::Critica,
                'iniciado_por_user_id' => null,
                'iniciado_at' => null,
                'completado_por_user_id' => null,
                'completado_at' => null,
                'cancelado_por_user_id' => null,
                'cancelado_at' => null,
                'motivo_cancelacion' => null,
                'version' => $plan->version + 1,
            ]);
        }

        return $plan->refresh();
    }

    /** @param array<string, mixed> $datos */
    private function actualizarContexto(
        PlanOperacional $plan,
        Folio $folio,
        bool $segregado,
        array $datos,
    ): void {
        $contexto = [
            ...($plan->contexto ?? []),
            ...$this->contextoBase($folio),
            ...$datos,
            'segregados' => $segregado ? 1 : 0,
            'porcentaje_actual' => $segregado ? 100 : 0,
        ];
        if ($plan->contexto === $contexto && $plan->prioridad === PrioridadOperacional::Critica) {
            return;
        }
        $plan->update([
            'contexto' => $contexto,
            'prioridad' => PrioridadOperacional::Critica,
            'version' => $plan->version + 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function contextoBase(Folio $folio): array
    {
        return [
            'planner_horizon' => 'rolling',
            'planner_compute' => config('planificador.compute'),
            'objetivo' => 'segregar_retenido_100_por_ciento',
            'folio_id' => $folio->id,
            'total' => 1,
            'umbral_porcentaje' => 100,
        ];
    }

    private function completar(PlanOperacional $plan, User $usuario): void
    {
        if ($plan->estado->esFinal()
            || $plan->maniobras()->whereIn('estado', [
                EstadoManiobraOperacional::EnEjecucion->value,
                EstadoManiobraOperacional::PausadaDiscrepancia->value,
            ])->exists()) {
            return;
        }
        $plan->update([
            'estado' => EstadoPlanOperacional::Completado,
            'completado_por_user_id' => $usuario->id,
            'completado_at' => now(),
            'version' => $plan->version + 1,
        ]);
    }

    private function cancelarManiobrasReversibles(
        PlanOperacional $plan,
        User $usuario,
        string $motivo,
    ): bool {
        $maniobras = $plan->maniobras()
            ->whereIn('estado', [
                EstadoManiobraOperacional::Pendiente->value,
                EstadoManiobraOperacional::EnEjecucion->value,
                EstadoManiobraOperacional::PausadaDiscrepancia->value,
            ])
            ->lockForUpdate()
            ->get();

        foreach ($maniobras as $maniobra) {
            if ($maniobra->estado === EstadoManiobraOperacional::PausadaDiscrepancia
                || ! $this->maniobras->cancelarReversible($maniobra, $usuario, $motivo)) {
                return false;
            }
        }

        return true;
    }

    private function planExistente(string $retencionId, bool $bloquear = false): ?PlanOperacional
    {
        $consulta = PlanOperacional::query()
            ->where('referencia_tipo', self::REFERENCIA)
            ->where('referencia_id', $retencionId);

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    /** @return array<int, string> */
    private function estadosActivosTarea(): array
    {
        return [
            EstadoTareaMovimiento::Bloqueada->value,
            EstadoTareaMovimiento::Pendiente->value,
            EstadoTareaMovimiento::Asumida->value,
            EstadoTareaMovimiento::EnProceso->value,
        ];
    }

    /** @param iterable<int, string|null> $camaras */
    private function planificadorDirigidoActivo(iterable $camaras): bool
    {
        return $this->despliegue->dirige($camaras);
    }
}
