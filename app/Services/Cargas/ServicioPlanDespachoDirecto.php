<?php

namespace App\Services\Cargas;

use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoPresenciaCargaAnden;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\EstadoReservaTareaMovimiento;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoBulto;
use App\Enums\TipoEventoCarga;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPasoManiobra;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\EventoCarga;
use App\Models\Movimiento;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\PresenciaCargaAnden;
use App\Models\ProcesoPrefrioFolio;
use App\Models\ReservaTareaMovimiento;
use App\Models\TareaMovimiento;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Estiba\ServicioManiobrasOperacionales;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Planificador\ServicioDesplieguePlanificador;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioPlanDespachoDirecto
{
    private const REFERENCIA = 'presencia_carga_anden';

    public function __construct(
        private readonly ServicioPlanesOperacionales $planes,
        private readonly ServicioDesplieguePlanificador $despliegue,
        private readonly ServicioManiobrasOperacionales $maniobras,
    ) {}

    public function sincronizar(
        PresenciaCargaAnden $presencia,
        User $usuario,
    ): ?PlanOperacional {
        if (! config('planificador.generacion_automatica')
            || config('planificador.mode') === 'off') {
            return null;
        }

        return DB::transaction(function () use ($presencia, $usuario): ?PlanOperacional {
            $presencia = PresenciaCargaAnden::query()
                ->with(['carga.temporada', 'anden'])
                ->lockForUpdate()
                ->findOrFail($presencia->id);

            if ($presencia->estado !== EstadoPresenciaCargaAnden::Activa
                || $presencia->bloqueo_carga_id === null
                || ! $presencia->carga->temporada?->activa) {
                return null;
            }

            $candidatos = $this->candidatos($presencia);
            $camaraIds = $candidatos
                ->pluck('camara_origen_id')
                ->filter()
                ->unique()
                ->values();
            $modo = $camaraIds->isEmpty()
                ? $this->despliegue->modoGlobal()
                : $this->despliegue->modoEfectivo($camaraIds);
            if ($modo === 'shadow') {
                $this->registrarShadow($presencia, $usuario, $candidatos);

                return $this->planExistente($presencia->id);
            }
            if ($modo !== 'guided' || ! in_array(config('planificador.compute'), ['tablet', 'server'], true)) {
                return $this->planExistente($presencia->id);
            }

            $plan = $this->planExistente($presencia->id, bloquear: true);
            if ($plan?->estado->esFinal()) {
                return $plan;
            }

            $abiertas = $this->asignacionesAbiertas($presencia);
            if ($abiertas->isEmpty()) {
                if ($plan) {
                    $this->completarPlanSiCorresponde($plan, $usuario);
                }

                return $plan?->refresh();
            }

            if (! $plan && $candidatos->isEmpty()) {
                return null;
            }

            if (! $plan) {
                $plan = PlanOperacional::create([
                    'temporada_id' => $presencia->carga->temporada_id,
                    'tipo' => TipoPlanOperacional::DespachoDirecto,
                    'estado' => EstadoPlanOperacional::Programado,
                    'prioridad' => PrioridadOperacional::Critica,
                    'titulo' => "Despacho directo {$presencia->carga->codigo} → {$presencia->anden->nombre}",
                    'motivo' => "Camión {$presencia->patente} confirmado en andén.",
                    'referencia_tipo' => self::REFERENCIA,
                    'referencia_id' => $presencia->id,
                    'contexto' => [
                        'planner_horizon' => 'rolling',
                        'planner_compute' => config('planificador.compute'),
                        'objetivo' => 'llevar_pallets_carga_a_anden_sin_almacenamiento_intermedio',
                        'carga_id' => $presencia->carga_id,
                        'anden_id' => $presencia->anden_id,
                        'patente' => $presencia->patente,
                    ],
                    'creado_por_user_id' => $usuario->id,
                    'programado_at' => now(),
                ]);
            }

            $this->sincronizarCandidatos($plan, $presencia, $usuario, $candidatos);

            return $plan->refresh();
        }, attempts: 3);
    }

    /**
     * Recalcula únicamente las presencias que pueden haber sido afectadas por
     * el folio movido o por las bandas de origen/destino del movimiento.
     */
    public function sincronizarTrasMovimiento(Movimiento $movimiento, User $usuario): void
    {
        if (config('planificador.mode') === 'off'
            || ! config('planificador.generacion_automatica')) {
            return;
        }

        if ($movimiento->tarea_movimiento_id) {
            $tarea = TareaMovimiento::query()->find($movimiento->tarea_movimiento_id);
            if (($tarea?->contexto['tipo_decision'] ?? null) === 'retiro_directo_anden') {
                // El servicio de despacho sincroniza nuevamente después de
                // cambiar la asignación a en_anden. Hacerlo aquí todavía la
                // vería pendiente y podría publicar una tarea transitoria falsa.
                return;
            }
        }

        $cargaIds = CargaFolio::query()
            ->where('folio_id', $movimiento->folio_id)
            ->whereHas('reservaActiva')
            ->pluck('carga_id');

        $posiciones = Posicion::query()
            ->whereIn('id', array_values(array_filter([
                $movimiento->posicion_origen_id,
                $movimiento->posicion_destino_id,
            ])))
            ->get(['id', 'camara_id', 'banda', 'nivel']);

        foreach ($posiciones as $posicion) {
            $foliosAfectados = UbicacionActual::query()
                ->whereHas('posicion', fn ($consulta) => $consulta
                    ->where('camara_id', $posicion->camara_id)
                    ->where('banda', $posicion->banda)
                    ->where('nivel', $posicion->nivel))
                ->pluck('folio_id');

            if ($foliosAfectados->isNotEmpty()) {
                $cargaIds = $cargaIds->merge(
                    CargaFolio::query()
                        ->whereIn('folio_id', $foliosAfectados)
                        ->whereIn('estado', [
                            EstadoCargaFolio::Pendiente->value,
                            EstadoCargaFolio::ConIncidencia->value,
                        ])
                        ->whereHas('reservaActiva')
                        ->pluck('carga_id'),
                );
            }
        }

        $presencias = PresenciaCargaAnden::query()
            ->where('estado', EstadoPresenciaCargaAnden::Activa->value)
            ->whereNotNull('bloqueo_carga_id')
            ->whereIn('carga_id', $cargaIds->unique()->values())
            ->orderBy('ingresada_at')
            ->get();

        foreach ($presencias as $presencia) {
            $this->sincronizar($presencia, $usuario);
        }
    }

    /**
     * Completa una entrega directa cuyo origen lógico es Prefrío. No inventa
     * una cámara, una posición ni un Movimiento de estiba inexistente.
     */
    public function completarDesdePrefrio(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
    ): TareaMovimiento {
        return DB::transaction(function () use ($tarea, $usuario, $dispositivo): TareaMovimiento {
            $tarea = TareaMovimiento::query()
                ->with('planOperacional')
                ->lockForUpdate()
                ->findOrFail($tarea->id);
            $contexto = $tarea->contexto ?? [];

            if ($tarea->tipo_movimiento !== TipoMovimiento::Retiro
                || ($contexto['tipo_decision'] ?? null) !== 'retiro_directo_anden'
                || ($contexto['origen_logico'] ?? null) !== 'tunel_prefrio') {
                throw new DomainException('La tarea no corresponde a una salida directa desde Prefrío.');
            }
            if ($tarea->estado !== EstadoTareaMovimiento::EnProceso) {
                throw new ConflictoOperacion(
                    'La salida desde Prefrío debe iniciar físicamente antes de confirmarse en andén.',
                );
            }
            if ($tarea->responsable_user_id !== $usuario->id
                || $tarea->dispositivo_id !== $dispositivo->id) {
                throw new ConflictoOperacion('La tarea pertenece a otro camarero o dispositivo.');
            }

            $reserva = ReservaTareaMovimiento::query()
                ->where('bloqueo_tarea_id', $tarea->id)
                ->lockForUpdate()
                ->first();
            if (! $reserva
                || $reserva->user_id !== $usuario->id
                || $reserva->dispositivo_id !== $dispositivo->id) {
                throw new ConflictoOperacion('La tarea perdió su claim operacional.');
            }

            $presencia = PresenciaCargaAnden::query()
                ->whereKey($contexto['presencia_carga_anden_id'] ?? null)
                ->where('estado', EstadoPresenciaCargaAnden::Activa->value)
                ->whereNotNull('bloqueo_carga_id')
                ->lockForUpdate()
                ->first();
            if (! $presencia
                || $presencia->anden_id !== ($contexto['anden_id'] ?? null)) {
                throw new ConflictoOperacion('El camión o el andén ya no corresponden a la tarea.');
            }

            $asignacion = CargaFolio::query()
                ->with('folio')
                ->lockForUpdate()
                ->findOrFail($contexto['carga_folio_id'] ?? '');
            if ($asignacion->carga_id !== $presencia->carga_id
                || $asignacion->folio_id !== $tarea->folio_id
                || $asignacion->estado !== EstadoCargaFolio::Pendiente
                || ! $asignacion->reservaActiva()->lockForUpdate()->exists()) {
                throw new ConflictoOperacion('El pallet ya no está pendiente en la carga indicada.');
            }
            if (UbicacionActual::query()
                ->where('folio_id', $asignacion->folio_id)
                ->lockForUpdate()
                ->exists()) {
                throw new ConflictoOperacion(
                    'El pallet ya posee una ubicación de cámara; debe ejecutarse como retiro desde cámara.',
                );
            }

            $prefrio = ProcesoPrefrioFolio::query()
                ->where('folio_id', $asignacion->folio_id)
                ->where('estado', EstadoFolioProcesoPrefrio::Aprobado->value)
                ->whereHas('proceso', fn ($consulta) => $consulta
                    ->where('estado', EstadoProcesoPrefrio::Aprobado->value))
                ->lockForUpdate()
                ->exists();
            if (! $prefrio) {
                throw new ConflictoOperacion('El pallet ya no posee un Prefrío aprobado para salida directa.');
            }

            $ahora = now();
            $asignacion->update([
                'estado' => EstadoCargaFolio::EnAnden,
                'anden_id' => $presencia->anden_id,
                'enviado_anden_por_user_id' => $usuario->id,
                'enviado_anden_desde_dispositivo_id' => $dispositivo->id,
                'enviado_anden_at' => $ahora,
            ]);

            $carga = $presencia->carga()->lockForUpdate()->firstOrFail();
            $estados = CargaFolio::query()
                ->where('carga_id', $carga->id)
                ->whereHas('reservaActiva')
                ->lockForUpdate()
                ->get(['estado'])
                ->pluck('estado');
            $todosEnAnden = $estados->isNotEmpty()
                && $estados->every(
                    fn (EstadoCargaFolio $estado): bool => $estado === EstadoCargaFolio::EnAnden,
                );
            $carga->update([
                'estado' => $todosEnAnden
                    ? EstadoCarga::Despachada
                    : EstadoCarga::DespachoParcial,
                'version' => $carga->version + 1,
                'actualizada_por_user_id' => $usuario->id,
            ]);

            EventoCarga::create([
                'carga_id' => $carga->id,
                'folio_id' => $asignacion->folio_id,
                'user_id' => $usuario->id,
                'tipo' => TipoEventoCarga::FolioEnviadoAnden,
                'datos' => [
                    'anden_id' => $presencia->anden_id,
                    'sin_movimiento_camara' => true,
                    'origen_logico' => 'tunel_prefrio',
                    'tarea_movimiento_id' => $tarea->id,
                ],
            ]);

            $reserva->update([
                'estado' => EstadoReservaTareaMovimiento::Completada,
                'bloqueo_tarea_id' => null,
                'bloqueo_posicion_id' => null,
                'completada_at' => $ahora,
                'version' => $reserva->version + 1,
            ]);
            $tarea = $this->maniobras->completarPasoSinMovimiento(
                $tarea,
                $usuario,
                $dispositivo,
            );

            $this->sincronizar($presencia, $usuario);

            return $tarea->refresh();
        }, attempts: 3);
    }

    public function cancelar(PresenciaCargaAnden $presencia, User $usuario, string $motivo): void
    {
        $plan = $this->planExistente($presencia->id, bloquear: true);
        if (! $plan || $plan->estado->esFinal()) {
            return;
        }

        $tareas = $plan->tareas()
            ->whereIn('estado', [
                EstadoTareaMovimiento::Pendiente->value,
                EstadoTareaMovimiento::Asumida->value,
                EstadoTareaMovimiento::EnProceso->value,
            ])
            ->orderBy('secuencia')
            ->lockForUpdate()
            ->get();

        if ($tareas->contains(
            fn (TareaMovimiento $tarea): bool => $tarea->estado === EstadoTareaMovimiento::EnProceso,
        )) {
            throw new DomainException(
                'No se puede liberar el andén mientras un pallet está físicamente en movimiento hacia él.',
            );
        }

        foreach ($tareas as $tarea) {
            $cancelada = $this->planes->cancelarPorReplanificacion(
                $tarea,
                $usuario,
                $motivo,
            );
            if ($cancelada) {
                $this->restaurarRecepcionTunel($cancelada, $usuario);
            }
        }

        $plan->refresh()->update([
            'estado' => EstadoPlanOperacional::Cancelado,
            'cancelado_por_user_id' => $usuario->id,
            'cancelado_at' => now(),
            'motivo_cancelacion' => $motivo,
            'version' => $plan->version + 1,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function candidatos(PresenciaCargaAnden $presencia): Collection
    {
        $asignaciones = $this->asignacionesAbiertas($presencia)
            ->where('estado', EstadoCargaFolio::Pendiente)
            ->values();
        if ($asignaciones->isEmpty()) {
            return collect();
        }

        $folioIds = $asignaciones->pluck('folio_id');
        $prefrio = ProcesoPrefrioFolio::query()
            ->whereIn('folio_id', $folioIds)
            ->where('estado', EstadoFolioProcesoPrefrio::Aprobado->value)
            ->whereHas('proceso', fn ($consulta) => $consulta
                ->where('estado', EstadoProcesoPrefrio::Aprobado->value))
            ->with(['proceso.tunel:id,codigo,nombre', 'posicion:id,tunel_prefrio_id,numero,etiqueta'])
            ->latest('retirado_at')
            ->get()
            ->unique('folio_id')
            ->keyBy('folio_id');

        $ubicadas = $asignaciones->filter(
            fn (CargaFolio $asignacion): bool => $asignacion->folio?->ubicacionActual?->posicion !== null,
        );
        $camaraIds = $ubicadas
            ->map(fn (CargaFolio $asignacion): string => $asignacion->folio->ubicacionActual->posicion->camara_id)
            ->unique()
            ->values();
        $ocupaciones = UbicacionActual::query()
            ->whereHas('posicion', fn ($consulta) => $consulta->whereIn('camara_id', $camaraIds))
            ->with(['folio', 'posicion'])
            ->get();

        $rutasBloqueadas = collect();
        $directos = collect();
        foreach ($asignaciones as $asignacion) {
            $folio = $asignacion->folio;
            $posicion = $folio?->ubicacionActual?->posicion;

            if (! $posicion) {
                $origenPrefrio = $prefrio->get($asignacion->folio_id);
                if ($origenPrefrio) {
                    $directos->push($this->candidatoRetiroPrefrio(
                        $asignacion,
                        $presencia,
                        $origenPrefrio,
                    ));
                }

                continue;
            }

            $bloqueadores = $ocupaciones
                ->filter(function (UbicacionActual $ubicacion) use ($asignacion, $posicion): bool {
                    $otra = $ubicacion->posicion;

                    return $ubicacion->folio_id !== $asignacion->folio_id
                        && $otra->camara_id === $posicion->camara_id
                        && $otra->nivel === $posicion->nivel
                        && $otra->banda === $posicion->banda
                        && $otra->posicion > $posicion->posicion;
                })
                ->sortByDesc(fn (UbicacionActual $ubicacion): int => $ubicacion->posicion->posicion)
                ->values();

            if ($bloqueadores->isEmpty()) {
                $directos->push($this->candidatoRetiroCamara($asignacion, $presencia));
            } else {
                $rutasBloqueadas->push(compact('asignacion', 'bloqueadores'));
            }
        }

        $foliosDirectos = $directos->pluck('folio_id')->flip();
        $despejes = $rutasBloqueadas
            ->map(function (array $ruta) use ($presencia, $foliosDirectos): ?array {
                /** @var UbicacionActual|null $bloqueador */
                $bloqueador = $ruta['bloqueadores']->first();
                if (! $bloqueador
                    || ! $bloqueador->folio?->activo
                    || $bloqueador->folio->tipo_bulto !== TipoBulto::Pallet
                    || $foliosDirectos->has($bloqueador->folio_id)) {
                    return null;
                }

                return $this->candidatoDespeje(
                    $bloqueador,
                    $ruta['asignacion'],
                    $presencia,
                );
            })
            ->filter()
            ->unique(fn (array $candidato): string => $candidato['folio_id'])
            ->values();

        return $directos
            ->concat($despejes)
            ->unique(fn (array $candidato): string => $candidato['folio_id'])
            ->take(max(1, (int) config('planificador.frontier_max', 4)))
            ->values();
    }

    /** @return Collection<int, CargaFolio> */
    private function asignacionesAbiertas(PresenciaCargaAnden $presencia): Collection
    {
        return CargaFolio::query()
            ->where('carga_id', $presencia->carga_id)
            ->whereIn('estado', [
                EstadoCargaFolio::Pendiente->value,
                EstadoCargaFolio::ConIncidencia->value,
            ])
            ->whereHas('reservaActiva')
            ->whereHas('folio', fn ($folios) => $folios
                ->where('activo', true)
                ->where('tipo_bulto', TipoBulto::Pallet->value))
            ->with('folio.ubicacionActual.posicion.camara')
            ->orderBy('asignado_at')
            ->lockForUpdate()
            ->get();
    }

    /** @return array<string, mixed> */
    private function candidatoRetiroCamara(
        CargaFolio $asignacion,
        PresenciaCargaAnden $presencia,
    ): array {
        $posicion = $asignacion->folio->ubicacionActual->posicion;

        return [
            'candidate_key' => "retiro:{$asignacion->folio_id}",
            'folio_id' => $asignacion->folio_id,
            'tipo_movimiento' => TipoMovimiento::Retiro,
            'camara_origen_id' => $posicion->camara_id,
            'posicion_origen_id' => $posicion->id,
            'instruccion' => "Retirar {$asignacion->folio->numero_folio} directamente a {$presencia->anden->nombre}.",
            'contexto' => [
                'candidate_key' => "retiro:{$asignacion->folio_id}",
                'tipo_decision' => 'retiro_directo_anden',
                'origen_logico' => 'camara',
                'presencia_carga_anden_id' => $presencia->id,
                'carga_id' => $presencia->carga_id,
                'carga_folio_id' => $asignacion->id,
                'anden_id' => $presencia->anden_id,
                'anden_nombre' => $presencia->anden->nombre,
                'patente' => $presencia->patente,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function candidatoRetiroPrefrio(
        CargaFolio $asignacion,
        PresenciaCargaAnden $presencia,
        ProcesoPrefrioFolio $prefrio,
    ): array {
        $proceso = $prefrio->proceso;

        return [
            'candidate_key' => "retiro:{$asignacion->folio_id}",
            'folio_id' => $asignacion->folio_id,
            'tipo_movimiento' => TipoMovimiento::Retiro,
            'camara_origen_id' => null,
            'posicion_origen_id' => null,
            'instruccion' => "Retirar {$asignacion->folio->numero_folio} desde {$proceso->tunel?->nombre} directamente a {$presencia->anden->nombre}.",
            'contexto' => [
                'candidate_key' => "retiro:{$asignacion->folio_id}",
                'tipo_decision' => 'retiro_directo_anden',
                'origen_logico' => 'tunel_prefrio',
                'presencia_carga_anden_id' => $presencia->id,
                'carga_id' => $presencia->carga_id,
                'carga_folio_id' => $asignacion->id,
                'anden_id' => $presencia->anden_id,
                'anden_nombre' => $presencia->anden->nombre,
                'patente' => $presencia->patente,
                'proceso_prefrio_id' => $proceso->id,
                'tunel_prefrio_id' => $proceso->tunel_prefrio_id,
                'tunel_nombre' => $proceso->tunel?->nombre,
                'posicion_tunel_prefrio_id' => $prefrio->posicion_tunel_prefrio_id,
                'posicion_tunel' => $prefrio->posicion?->etiqueta,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function candidatoDespeje(
        UbicacionActual $bloqueador,
        CargaFolio $habilitada,
        PresenciaCargaAnden $presencia,
    ): array {
        $posicion = $bloqueador->posicion;
        $clave = "despeje:{$bloqueador->folio_id}";

        return [
            'candidate_key' => $clave,
            'folio_id' => $bloqueador->folio_id,
            // Tipo inicial neutral para búsqueda global. Al materializar el
            // destino, el servidor lo normaliza a reubicación o traslado.
            'tipo_movimiento' => TipoMovimiento::TrasladoEntreCamaras,
            'camara_origen_id' => $posicion->camara_id,
            'posicion_origen_id' => $posicion->id,
            'instruccion' => "Despejar {$bloqueador->folio->numero_folio} para habilitar la salida directa de {$habilitada->folio->numero_folio}.",
            'contexto' => [
                'candidate_key' => $clave,
                'tipo_decision' => 'despeje_salida_directa',
                'tipo_movimiento_materializable' => true,
                'presencia_carga_anden_id' => $presencia->id,
                'carga_id' => $presencia->carga_id,
                'anden_id' => $presencia->anden_id,
                'anden_nombre' => $presencia->anden->nombre,
                'habilita_folio_id' => $habilitada->folio_id,
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidatos
     */
    private function sincronizarCandidatos(
        PlanOperacional $plan,
        PresenciaCargaAnden $presencia,
        User $usuario,
        Collection $candidatos,
    ): void {
        $cambio = false;
        $deseados = $candidatos->keyBy('candidate_key');
        $activas = $plan->tareas()
            ->whereIn('estado', [
                EstadoTareaMovimiento::Pendiente->value,
                EstadoTareaMovimiento::Asumida->value,
                EstadoTareaMovimiento::EnProceso->value,
            ])
            ->orderBy('secuencia')
            ->lockForUpdate()
            ->get();

        foreach ($activas as $tarea) {
            $clave = $tarea->contexto['candidate_key'] ?? null;
            if ($tarea->estado === EstadoTareaMovimiento::EnProceso
                || ($clave && $deseados->has($clave))) {
                continue;
            }

            $cancelada = $this->planes->cancelarPorReplanificacion(
                $tarea,
                $usuario,
                "La frontera de despacho directo cambió para el camión {$presencia->patente}.",
            );
            $cambio = $cancelada !== null || $cambio;
        }

        $activas = $plan->tareas()
            ->whereIn('estado', [
                EstadoTareaMovimiento::Pendiente->value,
                EstadoTareaMovimiento::Asumida->value,
                EstadoTareaMovimiento::EnProceso->value,
            ])
            ->get()
            ->keyBy(fn (TareaMovimiento $tarea): string => (string) ($tarea->contexto['candidate_key'] ?? $tarea->id));

        foreach ($candidatos as $candidato) {
            if ($activas->has($candidato['candidate_key'])) {
                continue;
            }

            $anterior = TareaMovimiento::query()
                ->with('planOperacional')
                ->where('folio_id', $candidato['folio_id'])
                ->whereIn('estado', [
                    EstadoTareaMovimiento::Bloqueada->value,
                    EstadoTareaMovimiento::Pendiente->value,
                    EstadoTareaMovimiento::Asumida->value,
                    EstadoTareaMovimiento::EnProceso->value,
                ])
                ->lockForUpdate()
                ->first();

            if ($anterior?->estado === EstadoTareaMovimiento::EnProceso) {
                continue;
            }
            if ($anterior
                && $anterior->plan_operacional_id !== $plan->id
                && in_array($anterior->planOperacional?->tipo, [
                    TipoPlanOperacional::DespachoDirecto,
                    TipoPlanOperacional::PreparacionInspeccion,
                ], true)) {
                continue;
            }

            $cancelada = null;
            if ($anterior && $anterior->plan_operacional_id !== $plan->id) {
                $cancelada = $this->planes->cancelarPorReplanificacion(
                    $anterior,
                    $usuario,
                    "Camión {$presencia->patente} presente en {$presencia->anden->nombre}; el objetivo prioritario cambió a despacho directo.",
                );
                if ($cancelada === null) {
                    continue;
                }
            }

            $nueva = $this->crearTareaCandidata($plan, $candidato);
            $cambio = true;
            if ($cancelada) {
                $cancelada->update(['reemplazada_por_tarea_id' => $nueva->id]);
            } else {
                $this->enlazarReemplazoDentroDelPlan($plan, $nueva, $candidato);
            }
        }

        if ($cambio) {
            $plan->refresh()->update(['version' => $plan->version + 1]);
        }
    }

    /** @param array<string, mixed> $candidato */
    private function crearTareaCandidata(PlanOperacional $plan, array $candidato): TareaMovimiento
    {
        $secuencia = ((int) TareaMovimiento::query()
            ->where('plan_operacional_id', $plan->id)
            ->lockForUpdate()
            ->max('secuencia')) + 1;

        $tarea = TareaMovimiento::create([
            'plan_operacional_id' => $plan->id,
            'secuencia' => $secuencia,
            'tipo_movimiento' => $candidato['tipo_movimiento'],
            'estado' => EstadoTareaMovimiento::Pendiente,
            'prioridad' => PrioridadOperacional::Critica,
            'folio_id' => $candidato['folio_id'],
            'camara_origen_id' => $candidato['camara_origen_id'] ?? null,
            'posicion_origen_id' => $candidato['posicion_origen_id'] ?? null,
            'camara_destino_id' => null,
            'posicion_destino_id' => null,
            'instruccion' => $candidato['instruccion'] ?? null,
            'contexto' => $candidato['contexto'],
        ]);

        return $this->maniobras->registrarUnitaria(
            $tarea,
            ($candidato['contexto']['tipo_decision'] ?? null) === 'retiro_directo_anden'
                ? TipoPasoManiobra::EntregaAnden
                : TipoPasoManiobra::MovimientoPermanente,
            $candidato['candidate_key'] ?? null,
        );
    }

    private function completarPlanSiCorresponde(PlanOperacional $plan, User $usuario): void
    {
        $enProceso = $plan->tareas()
            ->where('estado', EstadoTareaMovimiento::EnProceso->value)
            ->lockForUpdate()
            ->exists();
        if ($enProceso) {
            return;
        }

        $reversibles = $plan->tareas()
            ->whereIn('estado', [
                EstadoTareaMovimiento::Pendiente->value,
                EstadoTareaMovimiento::Asumida->value,
            ])
            ->lockForUpdate()
            ->get();
        foreach ($reversibles as $tarea) {
            $this->planes->cancelarPorReplanificacion(
                $tarea,
                $usuario,
                'La carga ya no posee pallets completos pendientes fuera del andén.',
            );
        }

        $plan->refresh()->update([
            'estado' => EstadoPlanOperacional::Completado,
            'completado_por_user_id' => $usuario->id,
            'completado_at' => now(),
            'version' => $plan->version + 1,
        ]);
    }

    /** @param array<string, mixed> $candidato */
    private function enlazarReemplazoDentroDelPlan(
        PlanOperacional $plan,
        TareaMovimiento $nueva,
        array $candidato,
    ): void {
        $objetivoFolioId = $candidato['contexto']['habilita_folio_id']
            ?? $candidato['folio_id'];
        $anterior = $plan->tareas()
            ->where('estado', EstadoTareaMovimiento::Cancelada->value)
            ->whereNull('reemplazada_por_tarea_id')
            ->orderByDesc('secuencia')
            ->lockForUpdate()
            ->get()
            ->first(fn (TareaMovimiento $tarea): bool => (
                ($tarea->contexto['habilita_folio_id'] ?? $tarea->folio_id)
                === $objetivoFolioId
            ));

        $anterior?->update(['reemplazada_por_tarea_id' => $nueva->id]);
    }

    private function restaurarRecepcionTunel(
        TareaMovimiento $cancelada,
        User $usuario,
    ): void {
        $anterior = $this->buscarAntecesoraRecepcionTunel($cancelada);
        if (! $anterior
            || $anterior->planOperacional->estado->esFinal()
            || UbicacionActual::query()->where('folio_id', $anterior->folio_id)->exists()) {
            return;
        }

        $restaurada = $this->planes->agregarTareaRolling(
            $anterior->planOperacional,
            [
                'folio_id' => $anterior->folio_id,
                'tipo_movimiento' => $anterior->tipo_movimiento,
                'prioridad' => $anterior->prioridad,
                'camara_origen_id' => $anterior->camara_origen_id,
                'posicion_origen_id' => $anterior->posicion_origen_id,
                'camara_destino_id' => $anterior->camara_destino_id,
                'posicion_destino_id' => $anterior->posicion_destino_id,
                'instruccion' => $anterior->instruccion,
                'contexto' => [
                    ...($anterior->contexto ?? []),
                    'restaurada_por_salida_camion' => true,
                    'restaurada_por_user_id' => $usuario->id,
                ],
            ],
        );
        $cancelada->update(['reemplazada_por_tarea_id' => $restaurada->id]);
    }

    private function buscarAntecesoraRecepcionTunel(
        TareaMovimiento $tarea,
    ): ?TareaMovimiento {
        $actual = $tarea;
        $visitadas = [];

        while (! in_array($actual->id, $visitadas, true)) {
            $visitadas[] = $actual->id;
            $anterior = TareaMovimiento::query()
                ->with('planOperacional')
                ->where('reemplazada_por_tarea_id', $actual->id)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();
            if (! $anterior) {
                return null;
            }
            if ($anterior->planOperacional->tipo === TipoPlanOperacional::RecepcionTunel) {
                return $anterior;
            }

            $actual = $anterior;
        }

        return null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidatos
     */
    private function registrarShadow(
        PresenciaCargaAnden $presencia,
        User $usuario,
        Collection $candidatos,
    ): void {
        EventoCarga::create([
            'carga_id' => $presencia->carga_id,
            'user_id' => $usuario->id,
            'tipo' => TipoEventoCarga::TareasGeneradas,
            'datos' => [
                'planner_mode' => 'shadow',
                'planner_compute' => config('planificador.compute'),
                'presencia_carga_anden_id' => $presencia->id,
                'candidatos' => $candidatos->map(fn (array $candidato): array => [
                    'candidate_key' => $candidato['candidate_key'],
                    'folio_id' => $candidato['folio_id'],
                    'tipo_movimiento' => $candidato['tipo_movimiento']->value,
                    'tipo_decision' => $candidato['contexto']['tipo_decision'] ?? null,
                    'habilita_folio_id' => $candidato['contexto']['habilita_folio_id'] ?? null,
                ])->values()->all(),
            ],
        ]);
    }

    private function planExistente(string $presenciaId, bool $bloquear = false): ?PlanOperacional
    {
        $consulta = PlanOperacional::query()
            ->where('referencia_tipo', self::REFERENCIA)
            ->where('referencia_id', $presenciaId);

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }
}
