<?php

namespace App\Services\Camaras;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoPosicion;
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
use App\Models\Camara;
use App\Models\Folio;
use App\Models\Movimiento;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ReservaCargaFolio;
use App\Models\ReservaPosicionInspeccionSag;
use App\Models\RetencionOperacionalFolio;
use App\Models\TareaMovimiento;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Estiba\ServicioManiobrasOperacionales;
use App\Services\Planificador\ServicioDesplieguePlanificador;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioOportunidadReordenamiento
{
    public const REFERENCIA = 'camara_reordenamiento';

    private const COSTO_POR_MOVIMIENTO = 150;

    private const RIESGO_POR_BLOCKER = 100;

    private const BENEFICIO_PERFIL_ELIMINADO = 500;

    private const BENEFICIO_BANDA_LIBERADA = 300;

    private const BENEFICIO_HUECO_RECIENTE = 200;

    public function __construct(
        private readonly CalculadorAfinidadBanda $afinidad,
        private readonly ServicioManiobrasOperacionales $maniobras,
        private readonly ServicioDesplieguePlanificador $despliegue,
    ) {}

    public function sincronizarTrasMovimiento(Movimiento $movimiento, User $usuario): void
    {
        if (! $this->planificadorObserva()) {
            return;
        }

        $posiciones = Posicion::query()
            ->whereIn('id', array_values(array_filter([
                $movimiento->posicion_origen_id,
                $movimiento->posicion_destino_id,
            ])))
            ->get();

        foreach ($posiciones->groupBy('camara_id') as $camaraId => $extremos) {
            $camara = Camara::query()->find($camaraId);
            if (! $camara) {
                continue;
            }

            $bandas = $extremos
                ->flatMap(fn (Posicion $posicion): array => [
                    $posicion->banda - 1,
                    $posicion->banda,
                    $posicion->banda + 1,
                ])
                ->filter(fn (int $banda): bool => $banda >= 1
                    && $banda <= $camara->cantidad_bandas)
                ->unique()
                ->sort()
                ->values()
                ->all();
            $huecoRecienteId = $extremos->contains('id', $movimiento->posicion_origen_id)
                ? $movimiento->posicion_origen_id
                : null;

            $this->sincronizarCamara(
                $camara,
                $usuario,
                $bandas,
                $huecoRecienteId,
                $movimiento->id,
            );
        }
    }

    /**
     * Recalcula una frontera local. Este método también constituye el punto de
     * validación autoritativo para propuestas futuras originadas por la tablet.
     *
     * @param  array<int, int>  $bandasAfectadas
     */
    public function sincronizarCamara(
        Camara $camara,
        User $usuario,
        array $bandasAfectadas,
        ?string $huecoRecienteId = null,
        ?string $movimientoDisparadorId = null,
    ): ?PlanOperacional {
        if (! $this->planificadorObserva($camara)) {
            return $this->planExistente($camara->id);
        }

        return DB::transaction(function () use (
            $camara,
            $usuario,
            $bandasAfectadas,
            $huecoRecienteId,
            $movimientoDisparadorId,
        ): ?PlanOperacional {
            $camara = Camara::query()->lockForUpdate()->findOrFail($camara->id);
            $plan = $this->planExistente($camara->id, bloquear: true);
            $bandas = collect($bandasAfectadas)
                ->map(fn (mixed $banda): int => (int) $banda)
                ->filter(fn (int $banda): bool => $banda >= 1
                    && $banda <= $camara->cantidad_bandas)
                ->unique()
                ->sort()
                ->values();

            if ($bandas->isEmpty()
                || $camara->estado !== EstadoCamara::Activa
                || $camara->contenido !== ContenidoCamara::Productos) {
                return $plan;
            }

            $maniobraActiva = $plan?->maniobras()
                ->whereIn('estado', [
                    EstadoManiobraOperacional::Pendiente->value,
                    EstadoManiobraOperacional::EnEjecucion->value,
                    EstadoManiobraOperacional::PausadaDiscrepancia->value,
                ])
                ->lockForUpdate()
                ->first();
            if ($maniobraActiva && in_array($maniobraActiva->estado, [
                EstadoManiobraOperacional::EnEjecucion,
                EstadoManiobraOperacional::PausadaDiscrepancia,
            ], true)) {
                $this->actualizarContexto($plan, [
                    'estado_decision' => 'recalculo_postergado',
                    'motivo_pendiente' => 'maniobra_en_ejecucion',
                    'bandas_analizadas' => $bandas->all(),
                    'movimiento_disparador_id' => $movimientoDisparadorId,
                ]);

                return $plan->refresh();
            }

            $resultado = $this->mejorCandidato(
                $camara,
                $bandas,
                $plan?->id,
                $huecoRecienteId,
                $movimientoDisparadorId,
            );
            $candidato = $resultado['accionable'] ?? $resultado['postergado'];

            if (! $candidato) {
                if (! $plan) {
                    return null;
                }
                if ($maniobraActiva) {
                    $this->maniobras->cancelarReversible(
                        $maniobraActiva,
                        $usuario,
                        'El nuevo estado físico ya no conserva una mejora neta.',
                    );
                }
                $this->actualizarContexto($plan, [
                    'estado_decision' => 'sin_mejora_neta',
                    'motivo_pendiente' => null,
                    'bandas_analizadas' => $bandas->all(),
                    'movimiento_disparador_id' => $movimientoDisparadorId,
                    'candidato' => null,
                ]);
                $this->completar($plan, $usuario);

                return $plan->refresh();
            }

            $plan = $this->asegurarPlan($plan, $camara, $usuario);
            $resumen = $this->resumenCandidato($candidato);
            if (($candidato['motivo_postergacion'] ?? null) !== null) {
                if ($maniobraActiva) {
                    $this->maniobras->cancelarReversible(
                        $maniobraActiva,
                        $usuario,
                        'Una labor de mayor prioridad ocupa la oportunidad local.',
                    );
                }
                $this->actualizarContexto($plan, [
                    'estado_decision' => 'postergada',
                    'motivo_pendiente' => $candidato['motivo_postergacion'],
                    'bandas_analizadas' => $bandas->all(),
                    'movimiento_disparador_id' => $movimientoDisparadorId,
                    'candidato' => $resumen,
                ]);

                return $plan->refresh();
            }

            if ($this->despliegue->modoParaCamara($camara) === 'shadow') {
                if ($maniobraActiva) {
                    $this->maniobras->cancelarReversible(
                        $maniobraActiva,
                        $usuario,
                        'El planificador pasó a modo shadow.',
                    );
                }
                $this->actualizarContexto($plan, [
                    'estado_decision' => 'shadow',
                    'motivo_pendiente' => null,
                    'bandas_analizadas' => $bandas->all(),
                    'movimiento_disparador_id' => $movimientoDisparadorId,
                    'candidato' => $resumen,
                ]);

                return $plan->refresh();
            }

            if ($maniobraActiva?->candidate_key !== $candidato['candidate_key']) {
                if ($maniobraActiva) {
                    $this->maniobras->cancelarReversible(
                        $maniobraActiva,
                        $usuario,
                        'La geometría o la economía del reordenamiento cambió.',
                    );
                }
                try {
                    $this->maniobras->crearCerrada($plan, $usuario, $candidato);
                } catch (ConflictoOperacion) {
                    $candidato['motivo_postergacion'] = 'labor_activa_previa';
                    $this->actualizarContexto($plan, [
                        'estado_decision' => 'postergada',
                        'motivo_pendiente' => 'labor_activa_previa',
                        'bandas_analizadas' => $bandas->all(),
                        'movimiento_disparador_id' => $movimientoDisparadorId,
                        'candidato' => $this->resumenCandidato($candidato),
                    ]);

                    return $plan->refresh();
                }
            }

            $this->actualizarContexto($plan, [
                'estado_decision' => 'publicada',
                'motivo_pendiente' => null,
                'bandas_analizadas' => $bandas->all(),
                'movimiento_disparador_id' => $movimientoDisparadorId,
                'candidato' => $resumen,
            ]);

            return $plan->refresh();
        }, attempts: 3);
    }

    /**
     * @param  Collection<int, int>  $bandas
     * @return array{accionable:?array<string,mixed>,postergado:?array<string,mixed>}
     */
    private function mejorCandidato(
        Camara $camara,
        Collection $bandas,
        ?string $planId,
        ?string $huecoRecienteId,
        ?string $movimientoDisparadorId,
    ): array {
        $bandasOperativas = BandaOperacional::query()
            ->where('camara_id', $camara->id)
            ->whereIn('numero', $bandas)
            ->where('modo', ModoBandaOperacional::Operativa->value)
            ->get()
            ->filter(fn (BandaOperacional $banda): bool => in_array(
                UsoBandaOperacional::TransitoProductoTerminado->value,
                $banda->usos_permitidos ?? [],
                true,
            ))
            ->pluck('numero')
            ->values();
        if ($bandasOperativas->count() < 2) {
            return ['accionable' => null, 'postergado' => null];
        }

        $posiciones = Posicion::query()
            ->where('camara_id', $camara->id)
            ->whereIn('banda', $bandasOperativas)
            ->where('estado', EstadoPosicion::Activa->value)
            ->orderBy('banda')
            ->orderBy('nivel')
            ->orderBy('posicion')
            ->get();
        $ubicaciones = UbicacionActual::query()
            ->whereIn('posicion_id', $posiciones->pluck('id'))
            ->with(['folio', 'posicion'])
            ->get();
        $ubicacionPorPosicion = $ubicaciones->keyBy('posicion_id');
        $folioIds = $ubicaciones->pluck('folio_id')->unique()->values();
        $foliosConCarga = ReservaCargaFolio::query()
            ->whereIn('folio_id', $folioIds)
            ->pluck('folio_id')
            ->flip();
        $foliosRetenidos = RetencionOperacionalFolio::query()
            ->whereIn('bloqueo_folio_id', $folioIds)
            ->pluck('bloqueo_folio_id')
            ->flip();
        $tareasActivas = TareaMovimiento::query()
            ->where(function ($consulta) use ($folioIds, $posiciones): void {
                $consulta->whereIn('folio_id', $folioIds)
                    ->orWhereIn('posicion_destino_id', $posiciones->pluck('id'));
            })
            ->whereIn('estado', $this->estadosActivosTarea())
            ->when($planId, fn ($consulta) => $consulta
                ->where('plan_operacional_id', '!=', $planId))
            ->get();
        $foliosConTarea = $tareasActivas->pluck('folio_id')->flip();
        $destinosConTarea = $tareasActivas->pluck('posicion_destino_id')->filter()->flip();
        $destinosReservadosSag = ReservaPosicionInspeccionSag::query()
            ->whereIn('posicion_id', $posiciones->pluck('id'))
            ->whereNotNull('clave_bloqueo')
            ->pluck('posicion_id')
            ->flip();
        $porBanda = $posiciones->groupBy('banda');
        $foliosPorBanda = $ubicaciones
            ->groupBy(fn (UbicacionActual $ubicacion): int => $ubicacion->posicion->banda)
            ->map(fn (Collection $grupo): Collection => $grupo->pluck('folio')->filter()->values());

        $destinos = $posiciones
            ->filter(fn (Posicion $posicion): bool => ! $ubicacionPorPosicion->has($posicion->id))
            ->filter(fn (Posicion $posicion): bool => $this->destinoFisicamenteViable(
                $posicion,
                $porBanda->get($posicion->banda, collect()),
                $ubicacionPorPosicion,
            ));
        $candidatos = collect();

        foreach ($ubicaciones as $ubicacionObjetivo) {
            $folio = $ubicacionObjetivo->folio;
            $origen = $ubicacionObjetivo->posicion;
            if (! $this->folioOptimizable($folio, $foliosConCarga, $foliosRetenidos)
                || $this->poseeCargaSuperior($origen, $ubicacionPorPosicion, $posiciones)) {
                continue;
            }

            $blockers = $this->blockers(
                $origen,
                $porBanda->get($origen->banda, collect()),
                $ubicacionPorPosicion,
            );
            if ($blockers === null
                || $blockers->contains(fn (UbicacionActual $ubicacion): bool => ! $this->folioOptimizable(
                    $ubicacion->folio,
                    $foliosConCarga,
                    $foliosRetenidos,
                ) || $this->poseeCargaSuperior(
                    $ubicacion->posicion,
                    $ubicacionPorPosicion,
                    $posiciones,
                ))) {
                continue;
            }

            $foliosOrigen = $foliosPorBanda->get($origen->banda, collect());
            $paresOrigen = $foliosOrigen->reject(fn (Folio $otro): bool => $otro->id === $folio->id)->values();
            $resumenAntes = $this->afinidad->resumir($foliosOrigen);
            $resumenDespues = $this->afinidad->resumir($paresOrigen);
            $perfilesEliminados = max(
                0,
                (int) $resumenAntes['perfiles_diferentes']
                    - (int) $resumenDespues['perfiles_diferentes'],
            );
            if ($perfilesEliminados === 0) {
                continue;
            }
            $afinidadOrigen = $this->afinidad->evaluar($folio, $paresOrigen);

            foreach ($destinos as $destino) {
                if ($destino->banda === $origen->banda) {
                    continue;
                }
                $foliosDestino = $foliosPorBanda->get($destino->banda, collect());
                if ($foliosDestino->contains(fn (Folio $existente): bool => ! $existente->activo
                    || $existente->tipo_bulto !== TipoBulto::Pallet)) {
                    continue;
                }
                $afinidadDestino = $this->afinidad->evaluar($folio, $foliosDestino);
                if ($afinidadDestino['mezclaria_clientes']
                    || $afinidadDestino['puntaje'] <= $afinidadOrigen['puntaje']) {
                    continue;
                }

                $esOportunidad = $destino->id === $huecoRecienteId;
                $movimientos = 1 + ($blockers->count() * 2);
                $beneficio = ($afinidadDestino['puntaje'] - $afinidadOrigen['puntaje'])
                    + ($perfilesEliminados * self::BENEFICIO_PERFIL_ELIMINADO)
                    + ($paresOrigen->isEmpty() ? self::BENEFICIO_BANDA_LIBERADA : 0)
                    + ($esOportunidad ? self::BENEFICIO_HUECO_RECIENTE : 0);
                $riesgo = ($blockers->count() * self::RIESGO_POR_BLOCKER)
                    + (abs($destino->banda - $origen->banda) * 10)
                    + (abs($destino->nivel - $origen->nivel) * 25);
                $beneficioNeto = $beneficio
                    - ($movimientos * self::COSTO_POR_MOVIMIENTO)
                    - $riesgo;
                if ($beneficioNeto <= 0) {
                    continue;
                }

                $motivoPostergacion = null;
                $foliosManiobra = $blockers->pluck('folio_id')->push($folio->id);
                $foliosDestinoIds = $foliosDestino->pluck('id');
                if ($foliosManiobra->contains(fn (string $id): bool => $foliosConTarea->has($id))
                    || $destinosConTarea->has($destino->id)
                    || $destinosReservadosSag->has($destino->id)
                    || $foliosDestinoIds->contains(fn (string $id): bool => $foliosConCarga->has($id)
                        || $foliosRetenidos->has($id)
                        || $foliosConTarea->has($id))) {
                    $motivoPostergacion = 'labor_activa_previa';
                }
                $candidatos->push($this->candidato(
                    $camara,
                    $folio,
                    $origen,
                    $destino,
                    $blockers,
                    $afinidadOrigen,
                    $afinidadDestino,
                    $beneficio,
                    $beneficioNeto,
                    $riesgo,
                    $perfilesEliminados,
                    $esOportunidad,
                    $movimientoDisparadorId,
                    $motivoPostergacion,
                    $porBanda->get($origen->banda, collect()),
                ));
            }
        }

        $ordenados = $candidatos->sort(function (array $a, array $b): int {
            return (($a['motivo_postergacion'] !== null) <=> ($b['motivo_postergacion'] !== null))
                ?: ($b['contexto']['es_movimiento_oportunidad'] <=> $a['contexto']['es_movimiento_oportunidad'])
                ?: ($b['contexto']['beneficio_neto'] <=> $a['contexto']['beneficio_neto'])
                ?: ($a['riesgo_operacional'] <=> $b['riesgo_operacional'])
                ?: strcmp($a['candidate_key'], $b['candidate_key']);
        })->values();

        return [
            'accionable' => $ordenados->first(
                fn (array $candidato): bool => $candidato['motivo_postergacion'] === null,
            ),
            'postergado' => $ordenados->first(
                fn (array $candidato): bool => $candidato['motivo_postergacion'] !== null,
            ),
        ];
    }

    /**
     * @param  Collection<int, UbicacionActual>  $blockers
     * @param  array<string, mixed>  $afinidadOrigen
     * @param  array<string, mixed>  $afinidadDestino
     * @param  Collection<int, Posicion>  $posicionesOrigen
     * @return array<string, mixed>
     */
    private function candidato(
        Camara $camara,
        Folio $folio,
        Posicion $origen,
        Posicion $destino,
        Collection $blockers,
        array $afinidadOrigen,
        array $afinidadDestino,
        int $beneficio,
        int $beneficioNeto,
        int $riesgo,
        int $perfilesEliminados,
        bool $esOportunidad,
        ?string $movimientoDisparadorId,
        ?string $motivoPostergacion,
        Collection $posicionesOrigen,
    ): array {
        $pasos = [];
        foreach ($blockers as $blocker) {
            $posicion = $blocker->posicion;
            $pasos[] = [
                'folio_id' => $blocker->folio_id,
                'tipo_movimiento' => TipoMovimiento::Retiro,
                'tipo_paso_maniobra' => TipoPasoManiobra::ExtraccionTemporal,
                'camara_origen_id' => $camara->id,
                'posicion_origen_id' => $posicion->id,
                'camara_destino_id' => null,
                'posicion_destino_id' => null,
                'instruccion' => "Retirar temporalmente {$blocker->folio->numero_folio}; continúe la maniobra completa.",
                'contexto' => [
                    'tipo_decision' => 'extraccion_temporal_reordenamiento',
                    'camara_retorno_id' => $camara->id,
                    'banda_retorno' => $origen->banda,
                    'nivel_retorno' => $origen->nivel,
                    'posicion_original' => $posicion->posicion,
                ],
            ];
        }

        $pasos[] = [
            'folio_id' => $folio->id,
            'tipo_movimiento' => TipoMovimiento::Reubicacion,
            'tipo_paso_maniobra' => TipoPasoManiobra::MovimientoPermanente,
            'camara_origen_id' => $camara->id,
            'posicion_origen_id' => $origen->id,
            'camara_destino_id' => $camara->id,
            'posicion_destino_id' => $destino->id,
            'instruccion' => "Reordenar {$folio->numero_folio} hacia una banda de mayor afinidad.",
            'contexto' => [
                'tipo_decision' => $esOportunidad
                    ? TipoPlanOperacional::MovimientoOportunidad->value
                    : TipoPlanOperacional::ReordenamientoCamara->value,
                'destino_precalculado_inmutable' => true,
                'afinidad_origen' => $afinidadOrigen['nivel']->value,
                'afinidad_destino' => $afinidadDestino['nivel']->value,
            ],
        ];

        foreach ($blockers->reverse()->values() as $indice => $blocker) {
            $profundidad = $origen->posicion + $indice;
            $posicionRetorno = $posicionesOrigen->first(
                fn (Posicion $posicion): bool => $posicion->nivel === $origen->nivel
                    && $posicion->posicion === $profundidad,
            );
            $pasos[] = [
                'folio_id' => $blocker->folio_id,
                'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                'tipo_paso_maniobra' => TipoPasoManiobra::RetornoBanda,
                'camara_origen_id' => null,
                'posicion_origen_id' => null,
                'camara_destino_id' => $camara->id,
                'posicion_destino_id' => $posicionRetorno->id,
                'instruccion' => "Devolver {$blocker->folio->numero_folio} a la profundidad resultante de la banda.",
                'contexto' => [
                    'tipo_decision' => 'retorno_blocker_reordenamiento',
                    'camara_retorno_id' => $camara->id,
                    'banda_retorno' => $origen->banda,
                    'nivel_retorno' => $origen->nivel,
                    'profundidad_resultante' => $profundidad,
                    'destino_precalculado_inmutable' => true,
                ],
            ];
        }

        $geometria = hash('sha256', json_encode([
            'camara' => $camara->id,
            'origen' => $origen->id,
            'destino' => $destino->id,
            'objetivo' => $folio->id,
            'blockers' => $blockers->pluck('folio_id')->all(),
        ], JSON_THROW_ON_ERROR));
        $bloqueos = $blockers->isEmpty()
            ? []
            : collect([
                ['camara_id' => $camara->id, 'banda' => $origen->banda, 'nivel' => $origen->nivel],
                ['camara_id' => $camara->id, 'banda' => $destino->banda, 'nivel' => $destino->nivel],
            ])->unique(fn (array $item): string => implode(':', $item))->values()->all();
        $objetivos = [TipoPlanOperacional::ReordenamientoCamara->value];
        if ($esOportunidad) {
            $objetivos[] = TipoPlanOperacional::MovimientoOportunidad->value;
        }

        return [
            'candidate_key' => 'reordenar:'.$geometria,
            'titulo' => "Reordenar {$folio->numero_folio}",
            'motivo' => 'Reduce mezcla y concentra el pallet en una banda de mayor afinidad.',
            'beneficio_estimado' => $beneficio,
            'riesgo_operacional' => $riesgo,
            'motivo_postergacion' => $motivoPostergacion,
            'contexto' => [
                'tipo_objetivo' => TipoPlanOperacional::ReordenamientoCamara->value,
                'objetivos' => $objetivos,
                'es_movimiento_oportunidad' => $esOportunidad,
                'movimiento_disparador_id' => $movimientoDisparadorId,
                'folio_objetivo_id' => $folio->id,
                'posicion_origen_id' => $origen->id,
                'posicion_destino_id' => $destino->id,
                'afinidad_origen' => $afinidadOrigen['nivel']->value,
                'afinidad_destino' => $afinidadDestino['nivel']->value,
                'puntaje_origen' => $afinidadOrigen['puntaje'],
                'puntaje_destino' => $afinidadDestino['puntaje'],
                'perfiles_eliminados_origen' => $perfilesEliminados,
                'blockers' => $blockers->count(),
                'blockers_retorno' => $blockers->count(),
                'movimientos_totales' => count($pasos),
                'beneficio_bruto' => $beneficio,
                'costo_fisico' => count($pasos) * self::COSTO_POR_MOVIMIENTO,
                'riesgo_operacional' => $riesgo,
                'beneficio_neto' => $beneficioNeto,
                'cerrable' => true,
                'geometry_hash' => $geometria,
            ],
            'bloqueos_banda' => $bloqueos,
            'pasos' => $pasos,
        ];
    }

    /**
     * Devuelve null cuando la línea no es una secuencia física continua que
     * pueda extraerse y reconstruirse sin inventar posiciones temporales.
     *
     * @param  Collection<int, Posicion>  $posicionesBanda
     * @param  Collection<string, UbicacionActual>  $ubicacionPorPosicion
     * @return Collection<int, UbicacionActual>|null
     */
    private function blockers(
        Posicion $origen,
        Collection $posicionesBanda,
        Collection $ubicacionPorPosicion,
    ): ?Collection {
        $linea = $posicionesBanda
            ->where('nivel', $origen->nivel)
            ->sortBy('posicion')
            ->values();
        $ocupadasDelante = $linea
            ->filter(fn (Posicion $posicion): bool => $posicion->posicion > $origen->posicion
                && $ubicacionPorPosicion->has($posicion->id));
        if ($ocupadasDelante->isEmpty()) {
            return collect();
        }

        $maxima = (int) $ocupadasDelante->max('posicion');
        for ($profundidad = $origen->posicion + 1; $profundidad <= $maxima; $profundidad++) {
            $posicion = $linea->firstWhere('posicion', $profundidad);
            if (! $posicion || ! $ubicacionPorPosicion->has($posicion->id)) {
                return null;
            }
        }

        return $ocupadasDelante
            ->sortByDesc('posicion')
            ->map(fn (Posicion $posicion): UbicacionActual => $ubicacionPorPosicion->get($posicion->id))
            ->values();
    }

    /**
     * @param  Collection<int, Posicion>  $posicionesBanda
     * @param  Collection<string, UbicacionActual>  $ubicacionPorPosicion
     */
    private function destinoFisicamenteViable(
        Posicion $destino,
        Collection $posicionesBanda,
        Collection $ubicacionPorPosicion,
    ): bool {
        $libreMasProfunda = $posicionesBanda->contains(
            fn (Posicion $posicion): bool => $posicion->nivel === $destino->nivel
                && $posicion->posicion < $destino->posicion
                && ! $ubicacionPorPosicion->has($posicion->id),
        );
        if ($libreMasProfunda) {
            return false;
        }
        if ($destino->nivel === 1) {
            return true;
        }

        $soporte = $posicionesBanda->first(
            fn (Posicion $posicion): bool => $posicion->posicion === $destino->posicion
                && $posicion->nivel === $destino->nivel - 1,
        );

        return $soporte !== null && $ubicacionPorPosicion->has($soporte->id);
    }

    /**
     * @param  Collection<string, UbicacionActual>  $ubicacionPorPosicion
     * @param  Collection<int, Posicion>  $posiciones
     */
    private function poseeCargaSuperior(
        Posicion $origen,
        Collection $ubicacionPorPosicion,
        Collection $posiciones,
    ): bool {
        return $posiciones->contains(
            fn (Posicion $posicion): bool => $posicion->banda === $origen->banda
                && $posicion->posicion === $origen->posicion
                && $posicion->nivel > $origen->nivel
                && $ubicacionPorPosicion->has($posicion->id),
        );
    }

    /**
     * @param  Collection<string, int>  $foliosConCarga
     * @param  Collection<string, int>  $foliosRetenidos
     */
    private function folioOptimizable(
        Folio $folio,
        Collection $foliosConCarga,
        Collection $foliosRetenidos,
    ): bool {
        return $folio->activo
            && $folio->tipo_bulto === TipoBulto::Pallet
            && $folio->estado_operacional === EstadoOperacionalFolio::Disponible
            && $folio->habilitacion_almacenamiento === HabilitacionAlmacenamientoFolio::Habilitado
            && ! $foliosConCarga->has($folio->id)
            && ! $foliosRetenidos->has($folio->id);
    }

    private function asegurarPlan(
        ?PlanOperacional $plan,
        Camara $camara,
        User $usuario,
    ): PlanOperacional {
        if (! $plan) {
            return PlanOperacional::create([
                'temporada_id' => $this->temporadaDeCamara($camara),
                'tipo' => TipoPlanOperacional::ReordenamientoCamara,
                'estado' => EstadoPlanOperacional::Programado,
                'prioridad' => PrioridadOperacional::Normal,
                'titulo' => "Reordenar {$camara->codigo}",
                'motivo' => 'Reducir mezcla y trabajo futuro sin desplazar objetivos obligatorios.',
                'referencia_tipo' => self::REFERENCIA,
                'referencia_id' => $camara->id,
                'contexto' => $this->contextoBase($camara),
                'creado_por_user_id' => $usuario->id,
                'programado_at' => now(),
            ]);
        }

        if ($plan->estado->esFinal()) {
            $plan->update([
                'estado' => EstadoPlanOperacional::Programado,
                'prioridad' => PrioridadOperacional::Normal,
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

    private function temporadaDeCamara(Camara $camara): string
    {
        $temporadaId = UbicacionActual::query()
            ->where('camara_id', $camara->id)
            ->join('folios', 'folios.id', '=', 'ubicaciones_actuales.folio_id')
            ->value('folios.temporada_id');

        return $temporadaId
            ?? app(ServicioTemporadaActiva::class)->buscar()?->id;
    }

    /** @param array<string, mixed> $datos */
    private function actualizarContexto(PlanOperacional $plan, array $datos): void
    {
        $contexto = [...($plan->contexto ?? []), ...$datos];
        if ($plan->contexto === $contexto && $plan->prioridad === PrioridadOperacional::Normal) {
            return;
        }
        $plan->update([
            'contexto' => $contexto,
            'prioridad' => PrioridadOperacional::Normal,
            'version' => $plan->version + 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function contextoBase(Camara $camara): array
    {
        return [
            'planner_horizon' => 'rolling',
            'planner_compute' => config('planificador.compute'),
            'objetivo' => TipoPlanOperacional::ReordenamientoCamara->value,
            'camara_id' => $camara->id,
            'solo_pallets_completos' => true,
            'jerarquia_afinidad' => ['cliente', 'marca', 'formato'],
            'prioridad_nivel' => 3,
        ];
    }

    /** @param array<string, mixed> $candidato */
    private function resumenCandidato(array $candidato): array
    {
        return [
            'candidate_key' => $candidato['candidate_key'],
            'titulo' => $candidato['titulo'],
            'motivo_postergacion' => $candidato['motivo_postergacion'],
            ...$candidato['contexto'],
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

    private function planExistente(string $camaraId, bool $bloquear = false): ?PlanOperacional
    {
        $consulta = PlanOperacional::query()
            ->where('referencia_tipo', self::REFERENCIA)
            ->where('referencia_id', $camaraId);

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

    private function planificadorObserva(?Camara $camara = null): bool
    {
        $modo = $this->despliegue->modoEfectivo($camara ? [$camara] : []);

        return config('planificador.horizon') === 'rolling'
            && ($modo === 'shadow'
                || ($modo === 'guided' && config('planificador.generacion_automatica')));
    }
}
