<?php

namespace App\Services\Camaras;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoLoteInspeccionSag;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoOperacionalFolio;
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
use App\Models\Camara;
use App\Models\Folio;
use App\Models\LoteInspeccionSagFolio;
use App\Models\Movimiento;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ReservaCargaFolio;
use App\Models\ReservaPosicionInspeccionSag;
use App\Models\RetencionOperacionalFolio;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Estiba\ServicioManiobrasOperacionales;
use App\Services\Planificador\ServicioDesplieguePlanificador;
use App\Services\Retenciones\ServicioPlanSegregacionRetenidos;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioDesocupacionProgramada
{
    public const REFERENCIA = 'camara_desocupacion';

    public function __construct(
        private readonly CalculadorAfinidadBanda $afinidad,
        private readonly ServicioManiobrasOperacionales $maniobras,
        private readonly ServicioPlanSegregacionRetenidos $segregacionRetenidos,
        private readonly ServicioDesplieguePlanificador $despliegue,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function candidatas(): array
    {
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        if (! $temporada) {
            return [];
        }

        return Camara::query()
            ->where('contenido', ContenidoCamara::Productos->value)
            ->where('estado', EstadoCamara::Activa->value)
            ->with(['bandasOperacionales', 'posiciones:id,camara_id,banda,posicion,nivel,estado'])
            ->get()
            ->map(fn (Camara $camara): array => $this->resumenCandidata($camara, $temporada))
            ->sort(function (array $a, array $b): int {
                return ($b['elegible'] <=> $a['elegible'])
                    ?: ($a['costo_movimientos_estimado'] <=> $b['costo_movimientos_estimado'])
                    ?: ($a['ocupacion_porcentaje'] <=> $b['ocupacion_porcentaje'])
                    ?: strcmp($a['camara']['codigo'], $b['camara']['codigo']);
            })
            ->values()
            ->all();
    }

    public function iniciar(Camara $camara, User $usuario, string $motivo): PlanOperacional
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3 || mb_strlen($motivo) > 500) {
            throw new DomainException('La desocupación programada requiere un motivo de 3 a 500 caracteres.');
        }
        $modo = $this->despliegue->modoParaCamara($camara);
        if ($modo === 'off') {
            throw new DomainException('El planificador está desactivado; no se inició la desocupación.');
        }

        $plan = DB::transaction(function () use ($camara, $usuario, $motivo, $modo): PlanOperacional {
            $camara = Camara::query()->lockForUpdate()->findOrFail($camara->id);
            $existente = $this->planExistente($camara->id, bloquear: true);
            if ($existente && ! $existente->estado->esFinal()) {
                return $existente;
            }

            $temporada = app(ServicioTemporadaActiva::class)->buscar(bloquear: true)
                ?? throw new DomainException('No existe una temporada activa para planificar el vaciado.');
            $this->validarInicio($camara, $temporada);
            $total = UbicacionActual::query()->where('camara_id', $camara->id)->count();
            $contexto = $this->contextoBase($camara, $total, $motivo);

            $plan = PlanOperacional::create([
                'temporada_id' => $temporada->id,
                'tipo' => TipoPlanOperacional::DesocupacionCamara,
                'estado' => EstadoPlanOperacional::Programado,
                'prioridad' => PrioridadOperacional::Alta,
                'titulo' => "Desocupar {$camara->codigo}",
                'motivo' => $motivo,
                'referencia_tipo' => self::REFERENCIA,
                'referencia_id' => $camara->id,
                'ciclo_referencia' => ($existente?->ciclo_referencia ?? 0) + 1,
                'contexto' => $contexto,
                'creado_por_user_id' => $usuario->id,
                'programado_at' => now(),
            ]);

            if ($modo === 'shadow') {
                $this->actualizarContexto($plan, [
                    'estado_desocupacion' => 'shadow',
                    'motivo_pendiente' => null,
                ]);

                return $plan->refresh();
            }
            if (! $this->planificadorDirigidoActivo($camara)) {
                throw new DomainException(
                    'La desocupación dirigida requiere generación automática, cálculo tablet y horizonte rolling.',
                );
            }

            $marca = $this->marcaMotivo($plan, $motivo);
            BandaOperacional::query()
                ->where('camara_id', $camara->id)
                ->lockForUpdate()
                ->get()
                ->each(fn (BandaOperacional $banda) => $banda->update([
                    'modo' => ModoBandaOperacional::EnVaciado,
                    'motivo_estado' => $marca,
                    'actualizado_por_user_id' => $usuario->id,
                    'version' => $banda->version + 1,
                ]));
            $camara->update([
                'version_plano' => $camara->version_plano + 1,
                'actualizado_por_user_id' => $usuario->id,
            ]);

            $this->sincronizarRetencionesExpuestas($camara, $plan, $usuario);

            return $this->sincronizarInterno($camara, $plan, $usuario);
        }, attempts: 3);

        return $this->cargar($plan);
    }

    public function sincronizar(Camara $camara, User $usuario): ?PlanOperacional
    {
        $plan = DB::transaction(function () use ($camara, $usuario): ?PlanOperacional {
            $camara = Camara::query()->lockForUpdate()->findOrFail($camara->id);
            $plan = $this->planExistente($camara->id, bloquear: true);
            if (! $plan || $plan->estado->esFinal()) {
                return $plan;
            }

            return $this->sincronizarInterno($camara, $plan, $usuario);
        }, attempts: 3);

        return $plan ? $this->cargar($plan) : null;
    }

    public function sincronizarPlan(
        Camara $camara,
        PlanOperacional $plan,
        User $usuario,
    ): PlanOperacional {
        $plan = DB::transaction(function () use ($camara, $plan, $usuario): PlanOperacional {
            $camara = Camara::query()->lockForUpdate()->findOrFail($camara->id);
            $plan = PlanOperacional::query()->lockForUpdate()->findOrFail($plan->id);
            $referenciaEsperada = match ($plan->tipo) {
                TipoPlanOperacional::DesocupacionCamara => self::REFERENCIA,
                TipoPlanOperacional::EvacuacionEmergencia => InterbloqueoEvacuacionEmergencia::REFERENCIA,
                default => null,
            };
            if ($plan->referencia_id !== $camara->id
                || $plan->referencia_tipo !== $referenciaEsperada) {
                throw new DomainException('El plan no corresponde a un vaciado de esta cámara.');
            }
            if ($plan->estado->esFinal()) {
                return $plan;
            }

            $this->sincronizarRetencionesExpuestas($camara, $plan, $usuario);

            return $this->sincronizarInterno($camara, $plan, $usuario);
        }, attempts: 3);

        return $this->cargar($plan);
    }

    public function sincronizarTrasMovimiento(Movimiento $movimiento, User $usuario): void
    {
        if (! $movimiento->camara_origen_id) {
            return;
        }
        $camara = Camara::query()->find($movimiento->camara_origen_id);
        if (! $camara) {
            return;
        }

        $emergencia = $this->planEmergenciaActivo($camara->id);
        if ($emergencia) {
            $this->sincronizarPlan($camara, $emergencia, $usuario);
        } else {
            $this->sincronizar($camara, $usuario);
        }

        if ($camara->contenido !== ContenidoCamara::Productos || ! $this->planificadorDirigidoActivo($camara)) {
            return;
        }

        // Un retiro de otra cámara puede liberar el destino que faltaba. Cada
        // sincronización vuelve a bloquear la cámara y comprobar su ciclo vigente.
        $pendientes = PlanOperacional::query()
            ->where('tipo', TipoPlanOperacional::DesocupacionCamara->value)
            ->where('referencia_tipo', self::REFERENCIA)
            ->where('referencia_id', '!=', $camara->id)
            ->whereNotIn('estado', [EstadoPlanOperacional::Completado->value, EstadoPlanOperacional::Cancelado->value])
            ->where('contexto->motivo_pendiente', 'sin_destino_compatible')
            ->orderBy('referencia_id')
            ->pluck('referencia_id')
            ->unique();
        foreach ($pendientes as $camaraId) {
            $pendiente = Camara::query()->find($camaraId);
            if ($pendiente) {
                $this->sincronizar($pendiente, $usuario);
            }
        }
    }

    public function cancelar(Camara $camara, User $usuario, string $motivo): PlanOperacional
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3 || mb_strlen($motivo) > 500) {
            throw new DomainException('La cancelación requiere un motivo de 3 a 500 caracteres.');
        }

        $plan = DB::transaction(function () use ($camara, $usuario, $motivo): PlanOperacional {
            $camara = Camara::query()->lockForUpdate()->findOrFail($camara->id);
            $plan = $this->planExistente($camara->id, bloquear: true)
                ?? throw new DomainException('La cámara no posee una desocupación programada.');
            if ($plan->estado === EstadoPlanOperacional::Completado) {
                throw new ConflictoOperacion(
                    'La cámara ya quedó vacía; el cierre completado no puede cancelarse.',
                );
            }
            if ($plan->estado === EstadoPlanOperacional::Cancelado) {
                return $plan;
            }

            $maniobras = $plan->maniobras()
                ->whereIn('estado', $this->estadosActivosManiobra())
                ->lockForUpdate()
                ->get();
            foreach ($maniobras as $maniobra) {
                if ($maniobra->estado === EstadoManiobraOperacional::PausadaDiscrepancia
                    || ! $this->maniobras->cancelarReversible($maniobra, $usuario, $motivo)) {
                    throw new ConflictoOperacion(
                        'La desocupación ya modificó la realidad física; termine la maniobra en curso antes de cancelarla.',
                    );
                }
            }

            $prefijo = $this->prefijoMotivo($plan);
            $restauradas = 0;
            BandaOperacional::query()
                ->where('camara_id', $camara->id)
                ->lockForUpdate()
                ->get()
                ->each(function (BandaOperacional $banda) use ($usuario, $prefijo, &$restauradas): void {
                    if ($banda->modo !== ModoBandaOperacional::EnVaciado
                        || ! str_starts_with((string) $banda->motivo_estado, $prefijo)) {
                        return;
                    }
                    $banda->update([
                        'modo' => ModoBandaOperacional::Operativa,
                        'motivo_estado' => null,
                        'actualizado_por_user_id' => $usuario->id,
                        'version' => $banda->version + 1,
                    ]);
                    $restauradas++;
                });
            if ($restauradas > 0) {
                $camara->update([
                    'version_plano' => $camara->version_plano + 1,
                    'actualizado_por_user_id' => $usuario->id,
                ]);
            }

            $plan->update([
                'estado' => EstadoPlanOperacional::Cancelado,
                'cancelado_por_user_id' => $usuario->id,
                'cancelado_at' => now(),
                'motivo_cancelacion' => $motivo,
                'contexto' => [
                    ...($plan->contexto ?? []),
                    'estado_desocupacion' => 'cancelada',
                    'bandas_restauradas' => $restauradas,
                    'movimientos_completados' => $plan->tareas()
                        ->where('estado', EstadoTareaMovimiento::Completada->value)
                        ->count(),
                ],
                'version' => $plan->version + 1,
            ]);

            return $plan->refresh();
        }, attempts: 3);

        return $this->cargar($plan);
    }

    private function sincronizarInterno(
        Camara $camara,
        PlanOperacional $plan,
        User $usuario,
    ): PlanOperacional {
        $claveEstado = $this->claveEstado($plan);
        if ($this->despliegue->modoParaCamara($camara) === 'shadow') {
            $this->actualizarContexto($plan, [$claveEstado => 'shadow']);

            return $plan->refresh();
        }
        if (! $this->planificadorDirigidoActivo($camara)) {
            return $plan;
        }

        $maniobraActiva = $plan->maniobras()
            ->whereIn('estado', $this->estadosActivosManiobra())
            ->lockForUpdate()
            ->first();
        if ($maniobraActiva && in_array($maniobraActiva->estado, [
            EstadoManiobraOperacional::EnEjecucion,
            EstadoManiobraOperacional::PausadaDiscrepancia,
        ], true)) {
            $this->actualizarProgreso($plan, $camara, [
                $claveEstado => 'en_ejecucion',
                'motivo_pendiente' => 'maniobra_en_ejecucion',
            ]);

            return $plan->refresh();
        }

        $restantes = UbicacionActual::query()->where('camara_id', $camara->id)->count();
        if ($restantes === 0) {
            if ($maniobraActiva) {
                $this->maniobras->cancelarReversible(
                    $maniobraActiva,
                    $usuario,
                    'La cámara ya se encuentra vacía.',
                );
            }
            $this->actualizarProgreso($plan, $camara, [
                $claveEstado => 'completada',
                'motivo_pendiente' => null,
                'lista_para_apagar' => true,
            ]);
            $this->completar($plan, $usuario);

            return $plan->refresh();
        }

        $resultado = $this->siguienteMovimiento($camara, $plan);
        $candidato = $resultado['candidato'];
        if (! $candidato) {
            if ($maniobraActiva) {
                $this->maniobras->cancelarReversible(
                    $maniobraActiva,
                    $usuario,
                    'Cambió la frontera física del vaciado programado.',
                );
            }
            $this->actualizarProgreso($plan, $camara, [
                $claveEstado => 'pendiente',
                'motivo_pendiente' => $resultado['motivo_pendiente'],
                'pendientes_prioridad' => $resultado['pendientes_prioridad'],
                'pendientes_inspeccion' => $resultado['pendientes_inspeccion'],
                'pendientes_retencion' => $resultado['pendientes_retencion'],
                'lista_para_apagar' => false,
            ]);

            return $plan->refresh();
        }

        if ($maniobraActiva?->candidate_key !== $candidato['candidate_key']) {
            if ($maniobraActiva) {
                $this->maniobras->cancelarReversible(
                    $maniobraActiva,
                    $usuario,
                    'Cambió la posición accesible o el destino compatible del vaciado.',
                );
            }
            try {
                $this->maniobras->crearCerrada($plan, $usuario, $candidato);
            } catch (ConflictoOperacion) {
                $this->actualizarProgreso($plan, $camara, [
                    $claveEstado => 'pendiente',
                    'motivo_pendiente' => 'labor_activa_previa',
                    'lista_para_apagar' => false,
                ]);

                return $plan->refresh();
            }
        }

        $this->actualizarProgreso($plan, $camara, [
            $claveEstado => 'publicada',
            'motivo_pendiente' => null,
            'pendientes_prioridad' => $resultado['pendientes_prioridad'],
            'pendientes_inspeccion' => $resultado['pendientes_inspeccion'],
            'pendientes_retencion' => $resultado['pendientes_retencion'],
            'lista_para_apagar' => false,
            'siguiente' => [
                'candidate_key' => $candidato['candidate_key'],
                'folio_id' => $candidato['contexto']['folio_objetivo_id'],
                'posicion_origen_id' => $candidato['contexto']['posicion_origen_id'],
                'posicion_destino_id' => $candidato['contexto']['posicion_destino_id'],
                'carga_id' => $candidato['contexto']['carga_id'],
                'afinidad_destino' => $candidato['contexto']['afinidad_destino'],
            ],
        ]);

        return $plan->refresh();
    }

    /**
     * @return array{
     *   candidato:?array<string,mixed>,motivo_pendiente:?string,
     *   pendientes_prioridad:int,pendientes_inspeccion:int,pendientes_retencion:int
     * }
     */
    private function siguienteMovimiento(Camara $camara, PlanOperacional $plan): array
    {
        $ubicaciones = UbicacionActual::query()
            ->where('camara_id', $camara->id)
            ->with(['folio', 'posicion'])
            ->get();
        $folioIds = $ubicaciones->pluck('folio_id')->unique()->values();
        $tareas = TareaMovimiento::query()
            ->whereIn('folio_id', $folioIds)
            ->where('plan_operacional_id', '!=', $plan->id)
            ->whereIn('estado', $this->estadosActivosTarea())
            ->get();
        $foliosConTarea = $tareas->pluck('folio_id')->flip();
        $foliosSag = LoteInspeccionSagFolio::query()
            ->whereIn('folio_id', $folioIds)
            ->whereHas('lote', fn ($consulta) => $consulta->whereIn('estado', [
                EstadoLoteInspeccionSag::Preparacion->value,
                EstadoLoteInspeccionSag::EnInspeccion->value,
                EstadoLoteInspeccionSag::ResultadoParcial->value,
            ]))
            ->pluck('folio_id')
            ->flip();
        $foliosRetenidos = RetencionOperacionalFolio::query()
            ->whereIn('bloqueo_folio_id', $folioIds)
            ->where('estado', EstadoRetencionOperacional::Activa->value)
            ->pluck('bloqueo_folio_id')
            ->flip();
        $cargas = $this->cargasPorFolio($folioIds);
        $porPosicion = $ubicaciones->keyBy('posicion_id');
        $posiciones = Posicion::query()
            ->where('camara_id', $camara->id)
            ->where('estado', EstadoPosicion::Activa->value)
            ->get();

        $origen = $ubicaciones
            ->filter(function (UbicacionActual $ubicacion) use (
                $foliosConTarea,
                $foliosSag,
                $foliosRetenidos,
                $porPosicion,
                $posiciones,
            ): bool {
                $folio = $ubicacion->folio;

                return $folio !== null
                    && $this->folioMovible($folio)
                    && ! $foliosConTarea->has($folio->id)
                    && ! $foliosSag->has($folio->id)
                    && ! $foliosRetenidos->has($folio->id)
                    && $ubicacion->posicion !== null
                    && $this->origenAccesible($ubicacion->posicion, $porPosicion, $posiciones);
            })
            ->sort(function (UbicacionActual $a, UbicacionActual $b) use ($cargas): int {
                $aTieneCarga = ($cargas[$a->folio_id] ?? null) !== null;
                $bTieneCarga = ($cargas[$b->folio_id] ?? null) !== null;

                return ($aTieneCarga <=> $bTieneCarga)
                    ?: ($b->posicion->posicion <=> $a->posicion->posicion)
                    ?: ($b->posicion->nivel <=> $a->posicion->nivel)
                    ?: ($a->posicion->banda <=> $b->posicion->banda)
                    ?: strcmp($a->folio_id, $b->folio_id);
            })
            ->first();

        if (! $origen) {
            return [
                'candidato' => null,
                'motivo_pendiente' => $foliosRetenidos->isNotEmpty()
                    ? 'retencion_prioritaria'
                    : ($foliosSag->isNotEmpty()
                        ? 'inspeccion_sag_activa'
                        : ($foliosConTarea->isNotEmpty()
                            ? 'trabajo_prioritario_activo'
                            : 'sin_pallet_accesible')),
                'pendientes_prioridad' => $foliosConTarea->count(),
                'pendientes_inspeccion' => $foliosSag->count(),
                'pendientes_retencion' => $foliosRetenidos->count(),
            ];
        }

        $destino = $this->destinoCompatible(
            $origen->folio,
            $camara,
            $plan,
            $cargas[$origen->folio_id] ?? null,
        );
        if (! $destino) {
            return [
                'candidato' => null,
                'motivo_pendiente' => 'sin_destino_compatible',
                'pendientes_prioridad' => $foliosConTarea->count(),
                'pendientes_inspeccion' => $foliosSag->count(),
                'pendientes_retencion' => $foliosRetenidos->count(),
            ];
        }

        return [
            'candidato' => $this->candidato(
                $plan,
                $camara,
                $origen,
                $destino['posicion'],
                $destino['afinidad'],
                $cargas[$origen->folio_id] ?? null,
                $destino['pallets_misma_carga'],
            ),
            'motivo_pendiente' => null,
            'pendientes_prioridad' => $foliosConTarea->count(),
            'pendientes_inspeccion' => $foliosSag->count(),
            'pendientes_retencion' => $foliosRetenidos->count(),
        ];
    }

    /**
     * @return array{posicion:Posicion,afinidad:array<string,mixed>,pallets_misma_carga:int}|null
     */
    private function destinoCompatible(
        Folio $folio,
        Camara $origen,
        PlanOperacional $plan,
        ?string $cargaId,
    ): ?array {
        $camarasDirigidas = $this->despliegue->idsCamarasDirigidas();
        $bandas = BandaOperacional::query()
            ->where('modo', ModoBandaOperacional::Operativa->value)
            ->where('camara_id', '!=', $origen->id)
            ->when(
                $camarasDirigidas !== null,
                fn ($consulta) => $consulta->whereIn('camara_id', $camarasDirigidas),
            )
            ->whereHas('camara', fn ($consulta) => $consulta
                ->where('estado', EstadoCamara::Activa->value)
                ->where('contenido', ContenidoCamara::Productos->value))
            ->get()
            ->filter(fn (BandaOperacional $banda): bool => in_array(
                UsoBandaOperacional::TransitoProductoTerminado->value,
                $banda->usos_permitidos ?? [],
                true,
            ));
        if ($bandas->isEmpty()) {
            return null;
        }

        $claves = $bandas
            ->map(fn (BandaOperacional $banda): string => "{$banda->camara_id}:{$banda->numero}")
            ->flip();
        $posiciones = Posicion::query()
            ->whereIn('camara_id', $bandas->pluck('camara_id')->unique())
            ->where('estado', EstadoPosicion::Activa->value)
            ->with('camara:id,codigo')
            ->get()
            ->filter(fn (Posicion $posicion): bool => $claves->has(
                "{$posicion->camara_id}:{$posicion->banda}",
            ));
        $ubicaciones = UbicacionActual::query()
            ->whereIn('posicion_id', $posiciones->pluck('id'))
            ->with('folio')
            ->get();
        $porPosicion = $ubicaciones->keyBy('posicion_id');
        $posicionPorId = $posiciones->keyBy('id');
        $bandasProtegidas = collect();
        if ($this->esEmergencia($plan)) {
            $folioIdsDestino = $ubicaciones->pluck('folio_id')->unique()->values();
            $foliosProtegidos = RetencionOperacionalFolio::query()
                ->whereIn('bloqueo_folio_id', $folioIdsDestino)
                ->where('estado', EstadoRetencionOperacional::Activa->value)
                ->pluck('bloqueo_folio_id')
                ->merge(LoteInspeccionSagFolio::query()
                    ->whereIn('folio_id', $folioIdsDestino)
                    ->whereHas('lote', fn ($consulta) => $consulta->whereIn('estado', [
                        EstadoLoteInspeccionSag::Preparacion->value,
                        EstadoLoteInspeccionSag::EnInspeccion->value,
                        EstadoLoteInspeccionSag::ResultadoParcial->value,
                    ]))
                    ->pluck('folio_id'))
                ->flip();
            $bandasProtegidas = $ubicaciones
                ->filter(fn (UbicacionActual $ubicacion): bool => $foliosProtegidos->has($ubicacion->folio_id))
                ->map(function (UbicacionActual $ubicacion) use ($posicionPorId): string {
                    $posicion = $posicionPorId->get($ubicacion->posicion_id);

                    return "{$posicion->camara_id}:{$posicion->banda}";
                })
                ->flip();
        }
        $foliosPorBanda = $ubicaciones->groupBy(function (UbicacionActual $ubicacion) use ($posicionPorId): string {
            $posicion = $posicionPorId->get($ubicacion->posicion_id);

            return "{$posicion->camara_id}:{$posicion->banda}";
        })->map(fn (Collection $grupo): Collection => $grupo->pluck('folio')->filter()->values());
        $destinosOcupados = TareaMovimiento::query()
            ->whereIn('posicion_destino_id', $posiciones->pluck('id'))
            ->where('plan_operacional_id', '!=', $plan->id)
            ->whereIn('estado', $this->estadosActivosTarea())
            ->pluck('posicion_destino_id')
            ->flip();
        $reservadosTarea = DB::table('reservas_tareas_movimiento')
            ->whereIn('bloqueo_posicion_id', $posiciones->pluck('id'))
            ->pluck('bloqueo_posicion_id')
            ->flip();
        $reservadosSag = ReservaPosicionInspeccionSag::query()
            ->whereIn('posicion_id', $posiciones->pluck('id'))
            ->whereNotNull('clave_bloqueo')
            ->pluck('posicion_id')
            ->flip();
        $cargasDestino = $this->cargasPorFolio($ubicaciones->pluck('folio_id')->unique()->values());

        return $posiciones
            ->filter(fn (Posicion $posicion): bool => ! $bandasProtegidas->has(
                "{$posicion->camara_id}:{$posicion->banda}",
            )
                && ! $porPosicion->has($posicion->id)
                && ! $destinosOcupados->has($posicion->id)
                && ! $reservadosTarea->has($posicion->id)
                && ! $reservadosSag->has($posicion->id)
                && $this->destinoFisicamenteViable($posicion, $posiciones, $porPosicion))
            ->map(function (Posicion $posicion) use (
                $folio,
                $foliosPorBanda,
                $cargaId,
                $cargasDestino,
            ): ?array {
                $folios = $foliosPorBanda->get("{$posicion->camara_id}:{$posicion->banda}", collect());
                if ($folios->contains(fn (Folio $existente): bool => ! $this->folioMovible($existente))) {
                    return null;
                }
                $afinidad = $this->afinidad->evaluar($folio, $folios);
                if ($afinidad['mezclaria_clientes']) {
                    return null;
                }
                $mismaCarga = $cargaId === null ? 0 : $folios
                    ->filter(fn (Folio $existente): bool => ($cargasDestino[$existente->id] ?? null) === $cargaId)
                    ->count();

                return [
                    'posicion' => $posicion,
                    'afinidad' => $afinidad,
                    'pallets_misma_carga' => $mismaCarga,
                ];
            })
            ->filter()
            ->sort(function (array $a, array $b): int {
                return ($b['pallets_misma_carga'] <=> $a['pallets_misma_carga'])
                    ?: ($b['afinidad']['puntaje'] <=> $a['afinidad']['puntaje'])
                    ?: strcmp($a['posicion']->camara?->codigo ?? '', $b['posicion']->camara?->codigo ?? '')
                    ?: ($a['posicion']->banda <=> $b['posicion']->banda)
                    ?: ($a['posicion']->nivel <=> $b['posicion']->nivel)
                    ?: ($a['posicion']->posicion <=> $b['posicion']->posicion);
            })
            ->first();
    }

    /** @param array<string, mixed> $afinidad */
    private function candidato(
        PlanOperacional $plan,
        Camara $camara,
        UbicacionActual $origen,
        Posicion $destino,
        array $afinidad,
        ?string $cargaId,
        int $palletsMismaCarga,
    ): array {
        $emergencia = $this->esEmergencia($plan);
        $tipoObjetivo = $emergencia
            ? TipoPlanOperacional::EvacuacionEmergencia
            : TipoPlanOperacional::DesocupacionCamara;
        $prioridad = $emergencia
            ? PrioridadOperacional::Critica
            : PrioridadOperacional::Alta;
        $geometria = hash('sha256', json_encode([
            'camara' => $camara->id,
            'folio' => $origen->folio_id,
            'origen' => $origen->posicion_id,
            'destino' => $destino->id,
        ], JSON_THROW_ON_ERROR));

        return [
            'candidate_key' => ($emergencia ? 'evacuacion-emergencia:' : 'desocupar:').$geometria,
            'titulo' => $emergencia
                ? "Evacuar por emergencia {$origen->folio->numero_folio}"
                : "Evacuar {$origen->folio->numero_folio}",
            'motivo' => $emergencia
                ? 'Lleva el pallet a una posición segura mediante un traslado permanente y cerrable.'
                : 'Avanza el vaciado programado con un traslado permanente y cerrable.',
            'beneficio_estimado' => 1000,
            'riesgo_operacional' => 0,
            'contexto' => [
                'tipo_objetivo' => $tipoObjetivo->value,
                'folio_objetivo_id' => $origen->folio_id,
                'posicion_origen_id' => $origen->posicion_id,
                'posicion_destino_id' => $destino->id,
                'carga_id' => $cargaId,
                'pallets_misma_carga_destino' => $palletsMismaCarga,
                'afinidad_destino' => $afinidad['nivel']->value,
                'blockers' => 0,
                'movimientos_totales' => 1,
                'cerrable' => true,
                'sin_custodia_temporal' => $emergencia,
                'geometry_hash' => $geometria,
            ],
            'bloqueos_banda' => [],
            'pasos' => [[
                'folio_id' => $origen->folio_id,
                'tipo_movimiento' => TipoMovimiento::TrasladoEntreCamaras,
                'tipo_paso_maniobra' => TipoPasoManiobra::MovimientoPermanente,
                'prioridad' => $prioridad,
                'camara_origen_id' => $camara->id,
                'posicion_origen_id' => $origen->posicion_id,
                'camara_destino_id' => $destino->camara_id,
                'posicion_destino_id' => $destino->id,
                'instruccion' => "Trasladar {$origen->folio->numero_folio} fuera de {$camara->codigo}.",
                'contexto' => [
                    'tipo_decision' => $tipoObjetivo->value,
                    ($emergencia ? 'camara_emergencia_id' : 'camara_desocupacion_id') => $camara->id,
                    'carga_id' => $cargaId,
                    'uso_banda_destino' => UsoBandaOperacional::TransitoProductoTerminado->value,
                    'destino_precalculado_inmutable' => true,
                ],
            ]],
        ];
    }

    private function validarInicio(Camara $camara, Temporada $temporada): void
    {
        if ($camara->contenido !== ContenidoCamara::Productos
            || $camara->estado !== EstadoCamara::Activa) {
            throw new DomainException('Solo puede desocuparse una cámara activa de producto terminado.');
        }
        if ($camara->bloqueo()->exists()) {
            throw new ConflictoOperacion(
                'La cámara posee una sesión de tablet abierta; ciérrela antes de iniciar el vaciado.',
            );
        }
        $bandas = BandaOperacional::query()
            ->where('camara_id', $camara->id)
            ->lockForUpdate()
            ->get();
        if ($bandas->count() !== $camara->cantidad_bandas
            || $bandas->contains(fn (BandaOperacional $banda): bool => $banda->modo !== ModoBandaOperacional::Operativa)) {
            throw new ConflictoOperacion(
                'Todas las bandas deben estar operativas antes de iniciar la desocupación programada.',
            );
        }
        if (ReservaPosicionInspeccionSag::query()
            ->whereNotNull('clave_bloqueo')
            ->whereHas('posicion', fn ($consulta) => $consulta->where('camara_id', $camara->id))
            ->lockForUpdate()
            ->exists()) {
            throw new ConflictoOperacion(
                'La cámara conserva posiciones reservadas para una inspección SAG activa.',
            );
        }
        if (TareaMovimiento::query()
            ->where('camara_destino_id', $camara->id)
            ->whereIn('estado', $this->estadosActivosTarea())
            ->lockForUpdate()
            ->exists()) {
            throw new ConflictoOperacion(
                'La cámara conserva ingresos operacionales pendientes; resuélvalos antes de bloquearla.',
            );
        }

        $invalidos = UbicacionActual::query()
            ->where('camara_id', $camara->id)
            ->whereHas('folio', fn ($consulta) => $consulta
                ->where(function ($folio) use ($temporada): void {
                    $folio->where('temporada_id', '!=', $temporada->id)
                        ->orWhere('activo', false)
                        ->orWhere('tipo_bulto', '!=', TipoBulto::Pallet->value);
                }))
            ->lockForUpdate()
            ->exists();
        if ($invalidos) {
            throw new ConflictoOperacion(
                'La cámara contiene existencias fuera del alcance seguro del vaciado de pallets completos.',
            );
        }
    }

    private function sincronizarRetencionesExpuestas(
        Camara $camara,
        PlanOperacional $plan,
        User $usuario,
    ): void {
        $ubicaciones = UbicacionActual::query()
            ->where('camara_id', $camara->id)
            ->with(['folio', 'posicion'])
            ->get();
        $porPosicion = $ubicaciones->keyBy('posicion_id');
        $posiciones = Posicion::query()
            ->where('camara_id', $camara->id)
            ->where('estado', EstadoPosicion::Activa->value)
            ->get();
        $retenciones = RetencionOperacionalFolio::query()
            ->where('estado', EstadoRetencionOperacional::Activa->value)
            ->whereIn('bloqueo_folio_id', $ubicaciones
                ->filter(fn (UbicacionActual $ubicacion): bool => $ubicacion->posicion !== null
                    && $this->origenAccesible($ubicacion->posicion, $porPosicion, $posiciones))
                ->pluck('folio_id'))
            ->get();

        foreach ($retenciones as $retencion) {
            $tareaAjena = TareaMovimiento::query()
                ->where('folio_id', $retencion->bloqueo_folio_id)
                ->where('plan_operacional_id', '!=', $plan->id)
                ->whereIn('estado', $this->estadosActivosTarea())
                ->exists();
            if (! $tareaAjena) {
                $this->segregacionRetenidos->sincronizar($retencion, $usuario);
            }
        }
    }

    private function origenAccesible(
        Posicion $origen,
        Collection $porPosicion,
        Collection $posiciones,
    ): bool {
        return ! $posiciones->contains(
            fn (Posicion $posicion): bool => $posicion->banda === $origen->banda
                && (($posicion->nivel === $origen->nivel
                    && $posicion->posicion > $origen->posicion)
                    || ($posicion->posicion === $origen->posicion
                        && $posicion->nivel > $origen->nivel))
                && $porPosicion->has($posicion->id),
        );
    }

    private function destinoFisicamenteViable(
        Posicion $destino,
        Collection $posiciones,
        Collection $porPosicion,
    ): bool {
        $mismaCamaraBanda = $posiciones->filter(
            fn (Posicion $posicion): bool => $posicion->camara_id === $destino->camara_id
                && $posicion->banda === $destino->banda,
        );
        if ($mismaCamaraBanda->contains(
            fn (Posicion $posicion): bool => $posicion->nivel === $destino->nivel
                && $posicion->posicion < $destino->posicion
                && ! $porPosicion->has($posicion->id),
        ) || $mismaCamaraBanda->contains(
            fn (Posicion $posicion): bool => (($posicion->nivel === $destino->nivel
                    && $posicion->posicion > $destino->posicion)
                    || ($posicion->posicion === $destino->posicion
                        && $posicion->nivel > $destino->nivel))
                && $porPosicion->has($posicion->id),
        )) {
            return false;
        }
        if ($destino->nivel === 1) {
            return true;
        }

        $soporte = $mismaCamaraBanda->first(
            fn (Posicion $posicion): bool => $posicion->posicion === $destino->posicion
                && $posicion->nivel === $destino->nivel - 1,
        );

        return $soporte !== null && $porPosicion->has($soporte->id);
    }

    private function folioMovible(Folio $folio): bool
    {
        return $folio->activo
            && $folio->tipo_bulto === TipoBulto::Pallet
            && $folio->estado_operacional === EstadoOperacionalFolio::Disponible
            && $folio->habilitacion_almacenamiento === HabilitacionAlmacenamientoFolio::Habilitado;
    }

    /** @param Collection<int, string> $folioIds @return array<string, string> */
    private function cargasPorFolio(Collection $folioIds): array
    {
        return ReservaCargaFolio::query()
            ->whereIn('reservas_carga_folio.folio_id', $folioIds)
            ->join('carga_folios', 'carga_folios.id', '=', 'reservas_carga_folio.carga_folio_id')
            ->pluck('carga_folios.carga_id', 'reservas_carga_folio.folio_id')
            ->all();
    }

    /** @return array<string, mixed> */
    private function resumenCandidata(Camara $camara, Temporada $temporada): array
    {
        $ubicaciones = UbicacionActual::query()
            ->where('camara_id', $camara->id)
            ->with('folio:id,temporada_id,tipo_bulto,activo')
            ->get();
        $capacidad = $camara->posiciones
            ->where('estado', EstadoPosicion::Activa)
            ->count();
        $razones = [];
        if ($camara->bloqueo()->exists()) {
            $razones[] = 'sesion_tablet_abierta';
        }
        if ($camara->bandasOperacionales->count() !== $camara->cantidad_bandas
            || $camara->bandasOperacionales->contains(
                fn (BandaOperacional $banda): bool => $banda->modo !== ModoBandaOperacional::Operativa,
            )) {
            $razones[] = 'bandas_no_operativas';
        }
        if ($ubicaciones->contains(fn (UbicacionActual $ubicacion): bool => ! $ubicacion->folio
            || $ubicacion->folio->temporada_id !== $temporada->id
            || ! $ubicacion->folio->activo
            || $ubicacion->folio->tipo_bulto !== TipoBulto::Pallet)) {
            $razones[] = 'existencias_fuera_de_alcance';
        }
        if ($this->planExistenteActivo($camara->id)) {
            $razones[] = 'desocupacion_activa';
        }
        if (TareaMovimiento::query()
            ->where('camara_destino_id', $camara->id)
            ->whereIn('estado', $this->estadosActivosTarea())
            ->exists()) {
            $razones[] = 'ingresos_operacionales_pendientes';
        }
        if (ReservaPosicionInspeccionSag::query()
            ->whereNotNull('clave_bloqueo')
            ->whereHas('posicion', fn ($consulta) => $consulta->where('camara_id', $camara->id))
            ->exists()) {
            $razones[] = 'reservas_inspeccion_activas';
        }

        return [
            'camara' => [
                'id' => $camara->id,
                'codigo' => $camara->codigo,
                'nombre' => $camara->nombre,
                'version_plano' => $camara->version_plano,
            ],
            'elegible' => $razones === [],
            'razones_no_elegible' => $razones,
            'pallets_actuales' => $ubicaciones->count(),
            'capacidad_efectiva' => $capacidad,
            'ocupacion_porcentaje' => $capacidad > 0
                ? (int) round(($ubicaciones->count() / $capacidad) * 100)
                : 0,
            'costo_movimientos_estimado' => $ubicaciones->count(),
            'pallets_con_tarea_activa' => TareaMovimiento::query()
                ->whereIn('folio_id', $ubicaciones->pluck('folio_id'))
                ->whereIn('estado', $this->estadosActivosTarea())
                ->count(),
            'pallets_retenidos' => RetencionOperacionalFolio::query()
                ->whereIn('bloqueo_folio_id', $ubicaciones->pluck('folio_id'))
                ->where('estado', EstadoRetencionOperacional::Activa->value)
                ->count(),
            'pallets_en_inspeccion' => LoteInspeccionSagFolio::query()
                ->whereIn('folio_id', $ubicaciones->pluck('folio_id'))
                ->whereHas('lote', fn ($consulta) => $consulta->whereIn('estado', [
                    EstadoLoteInspeccionSag::Preparacion->value,
                    EstadoLoteInspeccionSag::EnInspeccion->value,
                    EstadoLoteInspeccionSag::ResultadoParcial->value,
                ]))
                ->count(),
        ];
    }

    /** @param array<string, mixed> $datos */
    private function actualizarProgreso(
        PlanOperacional $plan,
        Camara $camara,
        array $datos,
    ): void {
        $restantes = UbicacionActual::query()->where('camara_id', $camara->id)->count();
        $total = max((int) (($plan->contexto ?? [])['total_inicial']
            ?? ($plan->contexto ?? [])['pallets_objetivo']
            ?? $restantes), $restantes);
        $evacuados = max(0, $total - $restantes);
        $this->actualizarContexto($plan, [
            ...$datos,
            'pallets_restantes' => $restantes,
            'pallets_evacuados' => $evacuados,
            'porcentaje_actual' => $total === 0 ? 100 : (int) floor(($evacuados / $total) * 100),
        ]);
    }

    /** @param array<string, mixed> $datos */
    private function actualizarContexto(PlanOperacional $plan, array $datos): void
    {
        $contexto = [...($plan->contexto ?? []), ...$datos];
        $prioridad = $this->prioridad($plan);
        if ($contexto === $plan->contexto && $plan->prioridad === $prioridad) {
            return;
        }
        $plan->update([
            'contexto' => $contexto,
            'prioridad' => $prioridad,
            'version' => $plan->version + 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function contextoBase(Camara $camara, int $total, string $motivo): array
    {
        return [
            'planner_horizon' => 'rolling',
            'planner_compute' => config('planificador.compute'),
            'objetivo' => 'desocupar_camara_100_por_ciento',
            'camara_id' => $camara->id,
            'motivo_programacion' => $motivo,
            'total_inicial' => $total,
            'pallets_restantes' => $total,
            'pallets_evacuados' => 0,
            'porcentaje_actual' => $total === 0 ? 100 : 0,
            'umbral_porcentaje' => 100,
            'prioridad_nivel' => 2,
            'solo_pallets_completos' => true,
            'una_maniobra_por_recalculo' => true,
            'lista_para_apagar' => false,
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

    private function cargar(PlanOperacional $plan): PlanOperacional
    {
        return $plan->refresh()->load([
            'temporada:id,codigo,nombre,activa',
            'creadoPor:id,name',
            'iniciadoPor:id,name',
            'tareas.folio:id,numero_folio,tipo_bulto,variedad,calibre,marca,exportadora,fecha_ingreso',
            'tareas.camaraOrigen:id,nombre',
            'tareas.posicionOrigen:id,camara_id,etiqueta,banda,posicion,nivel',
            'tareas.camaraDestino:id,nombre',
            'tareas.posicionDestino:id,camara_id,etiqueta,banda,posicion,nivel',
        ]);
    }

    private function planExistente(string $camaraId, bool $bloquear = false): ?PlanOperacional
    {
        $consulta = PlanOperacional::query()
            ->where('referencia_tipo', self::REFERENCIA)
            ->where('referencia_id', $camaraId)
            ->orderByDesc('ciclo_referencia');

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    private function planExistenteActivo(string $camaraId): bool
    {
        return PlanOperacional::query()
            ->where('referencia_tipo', self::REFERENCIA)
            ->where('referencia_id', $camaraId)
            ->whereNotIn('estado', [
                EstadoPlanOperacional::Completado->value,
                EstadoPlanOperacional::Cancelado->value,
            ])
            ->exists();
    }

    private function planEmergenciaActivo(string $camaraId): ?PlanOperacional
    {
        return PlanOperacional::query()
            ->where('tipo', TipoPlanOperacional::EvacuacionEmergencia->value)
            ->where('referencia_tipo', InterbloqueoEvacuacionEmergencia::REFERENCIA)
            ->where('referencia_id', $camaraId)
            ->whereNotIn('estado', [
                EstadoPlanOperacional::Completado->value,
                EstadoPlanOperacional::Cancelado->value,
            ])
            ->orderByDesc('ciclo_referencia')
            ->first();
    }

    private function esEmergencia(PlanOperacional $plan): bool
    {
        return $plan->tipo === TipoPlanOperacional::EvacuacionEmergencia;
    }

    private function claveEstado(PlanOperacional $plan): string
    {
        return $this->esEmergencia($plan) ? 'estado_emergencia' : 'estado_desocupacion';
    }

    private function prioridad(PlanOperacional $plan): PrioridadOperacional
    {
        return $this->esEmergencia($plan)
            ? PrioridadOperacional::Critica
            : PrioridadOperacional::Alta;
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

    /** @return array<int, string> */
    private function estadosActivosManiobra(): array
    {
        return [
            EstadoManiobraOperacional::Pendiente->value,
            EstadoManiobraOperacional::EnEjecucion->value,
            EstadoManiobraOperacional::PausadaDiscrepancia->value,
        ];
    }

    private function planificadorDirigidoActivo(Camara $camara): bool
    {
        return $this->despliegue->dirige([$camara]);
    }

    private function marcaMotivo(PlanOperacional $plan, string $motivo): string
    {
        return Str::limit($this->prefijoMotivo($plan).' '.$motivo, 500, '');
    }

    private function prefijoMotivo(PlanOperacional $plan): string
    {
        return 'Desocupación programada '.$plan->id.':';
    }
}
