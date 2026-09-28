<?php

namespace App\Services\Estiba;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoPosicion;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPasoManiobra;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Camaras\InterbloqueoEvacuacionEmergencia;
use App\Services\Planificador\ServicioDesplieguePlanificador;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioPlanesOperacionales
{
    public function __construct(
        private readonly ServicioReservasTareasMovimiento $reservas,
        private readonly ServicioManiobrasOperacionales $maniobras,
        private readonly InterbloqueoEvacuacionEmergencia $emergencias,
        private readonly ServicioDesplieguePlanificador $despliegue,
    ) {}

    /**
     * Punto único de creación para los generadores operacionales futuros.
     *
     * @param  array<int, array{
     *     folio_id: string,
     *     tipo_movimiento: TipoMovimiento,
     *     tipo_paso_maniobra?: TipoPasoManiobra|string,
     *     candidate_key?: string,
     *     prioridad?: PrioridadOperacional,
     *     camara_origen_id?: string|null,
     *     posicion_origen_id?: string|null,
     *     camara_destino_id?: string|null,
     *     posicion_destino_id?: string|null,
     *     instruccion?: string|null,
     *     contexto?: array<string, mixed>
     * }>  $tareas
     * @param  array<string, mixed>  $contexto
     */
    public function crear(
        Temporada $temporada,
        TipoPlanOperacional $tipo,
        string $titulo,
        User $creadoPor,
        array $tareas,
        PrioridadOperacional $prioridad = PrioridadOperacional::Normal,
        ?string $motivo = null,
        ?string $referenciaTipo = null,
        ?string $referenciaId = null,
        array $contexto = [],
        ?int $cicloReferencia = null,
    ): PlanOperacional {
        $titulo = trim($titulo);
        $motivo = filled($motivo) ? trim((string) $motivo) : null;
        $referenciaTipo = filled($referenciaTipo) ? trim((string) $referenciaTipo) : null;

        if ($titulo === '' || mb_strlen($titulo) > 180) {
            throw new DomainException('El plan requiere un título de hasta 180 caracteres.');
        }
        if ($tareas === []) {
            throw new DomainException('El plan operacional requiere al menos una tarea.');
        }
        if (($referenciaTipo === null) !== ($referenciaId === null)) {
            throw new DomainException('La referencia del plan debe incluir tipo e identificador.');
        }
        if ($referenciaTipo !== null && mb_strlen($referenciaTipo) > 80) {
            throw new DomainException('El tipo de referencia del plan supera el máximo permitido.');
        }
        if ($referenciaId !== null && ! Str::isUuid($referenciaId)) {
            throw new DomainException('El identificador de referencia del plan no es válido.');
        }
        if ($cicloReferencia !== null && $cicloReferencia < 1) {
            throw new DomainException('El ciclo de referencia debe ser positivo.');
        }

        return DB::transaction(function () use (
            $temporada,
            $tipo,
            $titulo,
            $creadoPor,
            $tareas,
            $prioridad,
            $motivo,
            $referenciaTipo,
            $referenciaId,
            $contexto,
            $cicloReferencia,
        ): PlanOperacional {
            $temporadaActiva = app(ServicioTemporadaActiva::class)->buscar(bloquear: true);

            if (! $temporadaActiva || $temporadaActiva->id !== $temporada->id) {
                throw new DomainException('Los planes solo pueden crearse en la temporada activa.');
            }
            if (! User::query()->whereKey($creadoPor->id)->where('activo', true)->exists()) {
                throw new DomainException('El usuario que origina el plan no se encuentra activo.');
            }

            $contextoPlan = [
                ...$contexto,
                'planner_horizon' => $contexto['planner_horizon'] ?? config('planificador.horizon'),
            ];

            $plan = PlanOperacional::create([
                'temporada_id' => $temporadaActiva->id,
                'tipo' => $tipo,
                'estado' => EstadoPlanOperacional::Programado,
                'prioridad' => $prioridad,
                'titulo' => $titulo,
                'motivo' => $motivo,
                'referencia_tipo' => $referenciaTipo,
                'referencia_id' => $referenciaId,
                ...($cicloReferencia === null ? [] : ['ciclo_referencia' => $cicloReferencia]),
                'contexto' => $contextoPlan,
                'creado_por_user_id' => $creadoPor->id,
                'programado_at' => now(),
            ]);

            foreach (array_values($tareas) as $indice => $datosTarea) {
                $this->crearTarea(
                    $plan,
                    $indice + 1,
                    $datosTarea,
                    $prioridad,
                );
            }

            return $this->cargar($plan);
        }, attempts: 3);
    }

    public function asumir(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
    ): TareaMovimiento {
        return DB::transaction(function () use ($tarea, $usuario, $dispositivo): TareaMovimiento {
            $tareaBloqueada = TareaMovimiento::query()
                ->with('planOperacional.temporada')
                ->lockForUpdate()
                ->findOrFail($tarea->id);
            $plan = $tareaBloqueada->planOperacional;

            $this->validarActor($usuario, $dispositivo);
            $this->validarPlanAsignable($plan);

            if ($tareaBloqueada->estado->esFinal()) {
                throw new DomainException('La tarea ya se encuentra finalizada.');
            }
            if ($tareaBloqueada->maniobra_operacional_id) {
                $this->maniobras->asumirPaso($tareaBloqueada, $usuario, $dispositivo);
            } else {
                $this->reservas->asumir($tareaBloqueada, $usuario, $dispositivo);
            }

            if ($plan->estado === EstadoPlanOperacional::Programado) {
                $plan->update([
                    'estado' => EstadoPlanOperacional::EnEjecucion,
                    'iniciado_por_user_id' => $usuario->id,
                    'iniciado_at' => now(),
                    'version' => $plan->version + 1,
                ]);
            }

            return $this->cargarTarea($tareaBloqueada->refresh());
        }, attempts: 3);
    }

    /**
     * Agrega una sola decisión a la frontera de un objetivo rolling vigente.
     *
     * @param  array<string, mixed>  $datosTarea
     */
    public function agregarTareaRolling(
        PlanOperacional $plan,
        array $datosTarea,
    ): TareaMovimiento {
        return DB::transaction(function () use ($plan, $datosTarea): TareaMovimiento {
            $planBloqueado = PlanOperacional::query()
                ->with('temporada')
                ->lockForUpdate()
                ->findOrFail($plan->id);

            if ($this->horizonte($planBloqueado) !== 'rolling'
                || $planBloqueado->estado->esFinal()
                || ! $planBloqueado->temporada?->activa) {
                throw new DomainException('El objetivo ya no admite nuevas decisiones rolling.');
            }

            $secuencia = ((int) TareaMovimiento::query()
                ->where('plan_operacional_id', $planBloqueado->id)
                ->lockForUpdate()
                ->max('secuencia')) + 1;
            $tarea = $this->crearTarea(
                $planBloqueado,
                $secuencia,
                $datosTarea,
                $planBloqueado->prioridad,
            );
            $planBloqueado->update(['version' => $planBloqueado->version + 1]);

            return $this->cargarTarea($tarea);
        }, attempts: 3);
    }

    public function cancelarPorReplanificacion(
        TareaMovimiento $tarea,
        User $usuario,
        string $motivo,
    ): ?TareaMovimiento {
        return DB::transaction(function () use ($tarea, $usuario, $motivo): ?TareaMovimiento {
            $tareaBloqueada = TareaMovimiento::query()
                ->with('planOperacional')
                ->lockForUpdate()
                ->findOrFail($tarea->id);

            if ($tareaBloqueada->estado->esFinal()) {
                return $tareaBloqueada;
            }
            if ($tareaBloqueada->maniobra_operacional_id) {
                $cancelada = $this->maniobras->cancelarReversible(
                    $tareaBloqueada->maniobraOperacional()->firstOrFail(),
                    $usuario,
                    $motivo,
                );

                return $cancelada ? $tareaBloqueada->refresh() : null;
            }
            if (! $this->reservas->liberarParaReplanificacion($tareaBloqueada, $motivo)) {
                return null;
            }

            $tareaBloqueada->update([
                'estado' => EstadoTareaMovimiento::Cancelada,
                'cancelada_at' => now(),
                'cancelada_por_user_id' => $usuario->id,
                'motivo_cancelacion' => Str::limit(trim($motivo), 255, ''),
                'version' => $tareaBloqueada->version + 1,
            ]);
            $plan = $tareaBloqueada->planOperacional;
            $plan->update(['version' => $plan->version + 1]);

            return $tareaBloqueada->refresh();
        }, attempts: 3);
    }

    public function materializarDestino(
        TareaMovimiento $tarea,
        Posicion $posicion,
        User $usuario,
        Dispositivo $dispositivo,
        ?int $versionTarea = null,
        ?int $versionPlan = null,
        ?int $versionCamara = null,
    ): TareaMovimiento {
        return DB::transaction(function () use (
            $tarea,
            $posicion,
            $usuario,
            $dispositivo,
            $versionTarea,
            $versionPlan,
            $versionCamara,
        ): TareaMovimiento {
            $this->validarActor($usuario, $dispositivo);
            $tareaBloqueada = TareaMovimiento::query()
                ->with('planOperacional')
                ->lockForUpdate()
                ->findOrFail($tarea->id);
            $plan = $tareaBloqueada->planOperacional;
            if ($this->horizonte($plan) !== 'rolling') {
                throw new DomainException(
                    'La materialización dinámica de destinos solo está disponible para planes rolling.',
                );
            }

            $contexto = $tareaBloqueada->contexto ?? [];
            $tipoDecision = $contexto['tipo_decision'] ?? null;
            $this->maniobras->validarPasoActual($tareaBloqueada);
            if (($contexto['destino_precalculado_inmutable'] ?? false) === true
                && $tareaBloqueada->posicion_destino_id !== $posicion->id) {
                throw new ConflictoOperacion(
                    'El destino útil precalculado pertenece a la maniobra cerrada y no puede sustituirse.',
                );
            }
            if (in_array($tipoDecision, ['despeje_salida_directa', 'despeje_concentracion'], true)
                && ($contexto['tipo_movimiento_materializable'] ?? false) === true) {
                if ($versionTarea !== null && $tareaBloqueada->version !== $versionTarea) {
                    throw new ConflictoOperacion('La tarea cambió desde el snapshot utilizado por la tablet.');
                }
                if ($tareaBloqueada->estado !== EstadoTareaMovimiento::Asumida
                    || ! $tareaBloqueada->camara_origen_id
                    || ! $tareaBloqueada->posicion_origen_id) {
                    throw new ConflictoOperacion(
                        'El despeje ya no se encuentra disponible para materializar un destino.',
                    );
                }

                $tipoMaterializado = $posicion->camara_id === $tareaBloqueada->camara_origen_id
                    ? TipoMovimiento::Reubicacion
                    : TipoMovimiento::TrasladoEntreCamaras;
                if ($tareaBloqueada->tipo_movimiento !== $tipoMaterializado) {
                    $tareaBloqueada->update([
                        'tipo_movimiento' => $tipoMaterializado,
                        'version' => $tareaBloqueada->version + 1,
                    ]);
                    $versionTarea = $tareaBloqueada->version;
                }
            }

            if ($tipoDecision === 'concentrar_carga') {
                $this->validarDestinoConcentracion($tareaBloqueada, $posicion);
            }
            if ($tareaBloqueada->tipo_paso_maniobra === TipoPasoManiobra::RetornoBanda) {
                $this->validarDestinoRetorno($tareaBloqueada, $posicion);
            }

            $this->reservas->materializarDestino(
                $tareaBloqueada,
                $posicion,
                $usuario,
                $dispositivo,
                $versionTarea,
                $versionPlan,
                $versionCamara,
            );

            return $this->cargarTarea($tareaBloqueada->refresh());
        }, attempts: 3);
    }

    public function iniciar(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
    ): TareaMovimiento {
        return DB::transaction(function () use ($tarea, $usuario, $dispositivo): TareaMovimiento {
            $tareaBloqueada = TareaMovimiento::query()
                ->with('planOperacional.temporada')
                ->lockForUpdate()
                ->findOrFail($tarea->id);
            $this->validarActor($usuario, $dispositivo);
            $this->validarPlanAsignable($tareaBloqueada->planOperacional);
            $this->maniobras->validarPasoActual($tareaBloqueada);
            $this->reservas->iniciar($tareaBloqueada, $usuario, $dispositivo);

            return $this->cargarTarea($tareaBloqueada->refresh());
        }, attempts: 3);
    }

    public function renovar(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
    ): TareaMovimiento {
        $tareaRenovada = DB::transaction(function () use ($tarea, $usuario, $dispositivo): ?TareaMovimiento {
            $tareaBloqueada = TareaMovimiento::query()
                ->with('planOperacional.temporada')
                ->lockForUpdate()
                ->findOrFail($tarea->id);

            $this->validarActor($usuario, $dispositivo);
            $this->validarPlanAsignable($tareaBloqueada->planOperacional);
            $reserva = $this->reservas->renovar($tareaBloqueada, $usuario, $dispositivo);

            return $reserva ? $this->cargarTarea($tareaBloqueada->refresh()) : null;
        }, attempts: 3);

        if (! $tareaRenovada) {
            throw new ConflictoOperacion(
                'La reserva de la tarea expiró; vuelva a asumirla antes de continuar.',
            );
        }

        return $tareaRenovada;
    }

    public function liberar(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
    ): TareaMovimiento {
        return DB::transaction(function () use ($tarea, $usuario, $dispositivo): TareaMovimiento {
            $tareaBloqueada = TareaMovimiento::query()
                ->with('planOperacional.temporada')
                ->lockForUpdate()
                ->findOrFail($tarea->id);

            $this->validarActor($usuario, $dispositivo);
            $this->validarPlanAsignable($tareaBloqueada->planOperacional);

            if ($tareaBloqueada->maniobra_operacional_id) {
                $this->maniobras->liberarAntesDeIniciar(
                    $tareaBloqueada,
                    $usuario,
                    $dispositivo,
                );
            } else {
                $this->reservas->liberar($tareaBloqueada, $usuario, $dispositivo);
            }

            return $this->cargarTarea($tareaBloqueada->refresh());
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    public function snapshot(PlanOperacional $plan): array
    {
        $plan = $this->cargar($plan->refresh());
        $horizon = $this->horizonte($plan);
        $cameraIds = $plan->tareas
            ->flatMap(fn (TareaMovimiento $tarea): array => array_filter([
                $tarea->camara_origen_id,
                $tarea->camara_destino_id,
            ]))
            ->unique()
            ->values();
        $camaras = Camara::query()
            ->whereIn('id', $cameraIds)
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
        $tareas = $plan->tareas->map(fn (TareaMovimiento $tarea): array => [
            'id' => $tarea->id,
            'version' => $tarea->version,
            'estado' => $tarea->estado->value,
            'folio_id' => $tarea->folio_id,
            'camara_origen_id' => $tarea->camara_origen_id,
            'posicion_origen_id' => $tarea->posicion_origen_id,
            'camara_destino_id' => $tarea->camara_destino_id,
            'posicion_destino_id' => $tarea->posicion_destino_id,
            'destino_reservado' => (bool) $tarea->reservaActiva?->bloqueo_posicion_id,
            'maniobra_id' => $tarea->maniobra_operacional_id,
            'secuencia_maniobra' => $tarea->secuencia_maniobra,
            'tipo_paso_maniobra' => $tarea->tipo_paso_maniobra?->value,
        ])->values();
        $modoEfectivo = $this->despliegue->modoEfectivo($cameraIds);
        $versionData = [
            'plan' => [$plan->id, $plan->version, $plan->estado->value],
            'planner' => [
                config('planificador.mode'),
                $modoEfectivo,
                config('planificador.compute'),
                config('planificador.horizon'),
                config('planificador.rollout_camaras', []),
            ],
            'camaras' => $camaras->all(),
            'tareas' => $tareas->all(),
        ];

        return [
            'snapshot_version' => hash('sha256', json_encode($versionData, JSON_THROW_ON_ERROR)),
            'generado_at' => now()->toIso8601String(),
            'planner' => [
                'mode' => config('planificador.mode'),
                'mode_efectivo' => $modoEfectivo,
                'compute' => config('planificador.compute'),
                'horizon' => $horizon,
                'frontier_max' => config('planificador.frontier_max'),
                'rollout_limitado' => config('planificador.rollout_camaras', []) !== [],
            ],
            'plan' => [
                'id' => $plan->id,
                'tipo' => $plan->tipo->value,
                'estado' => $plan->estado->value,
                'prioridad' => $plan->prioridad->value,
                'titulo' => $plan->titulo,
                'version' => $plan->version,
            ],
            'camaras' => $camaras,
            'tareas' => $tareas,
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function crearTarea(
        PlanOperacional $plan,
        int $secuencia,
        array $datos,
        PrioridadOperacional $prioridadPlan,
    ): TareaMovimiento {
        $tipo = $datos['tipo_movimiento'] ?? null;

        if (! $tipo instanceof TipoMovimiento || $tipo === TipoMovimiento::Reversion) {
            throw new DomainException('La tarea requiere un tipo de movimiento físico válido.');
        }

        $folio = Folio::query()->lockForUpdate()->find($datos['folio_id'] ?? null);
        if (! $folio
            || $folio->temporada_id !== $plan->temporada_id
            || ! $folio->activo
            || $folio->tipo_bulto !== TipoBulto::Pallet) {
            throw new DomainException('Las tareas operacionales solo admiten pallets completos activos de la temporada.');
        }
        $tareaActiva = TareaMovimiento::query()
            ->where('folio_id', $folio->id)
            ->whereIn('estado', [
                EstadoTareaMovimiento::Bloqueada->value,
                EstadoTareaMovimiento::Pendiente->value,
                EstadoTareaMovimiento::Asumida->value,
                EstadoTareaMovimiento::EnProceso->value,
            ])
            ->lockForUpdate()
            ->first(['id']);
        if ($tareaActiva) {
            throw new ConflictoOperacion('El pallet ya posee una tarea operacional activa.');
        }

        $camaraOrigen = $this->camara($datos['camara_origen_id'] ?? null);
        $posicionOrigen = $this->posicion($datos['posicion_origen_id'] ?? null);
        $camaraDestino = $this->camara($datos['camara_destino_id'] ?? null);
        $posicionDestino = $this->posicion($datos['posicion_destino_id'] ?? null);
        $rolling = ($plan->contexto['planner_horizon'] ?? config('planificador.horizon')) === 'rolling';

        $this->validarExtremo($camaraOrigen, $posicionOrigen, 'origen');
        $this->validarExtremo($camaraDestino, $posicionDestino, 'destino', $rolling);
        $rolling
            ? $this->validarEstructuraRolling($tipo, $camaraOrigen, $posicionOrigen, $camaraDestino, $posicionDestino)
            : $this->validarEstructuraBatch($tipo, $camaraOrigen, $posicionOrigen, $camaraDestino, $posicionDestino);

        $prioridad = $datos['prioridad'] ?? $prioridadPlan;
        if (! $prioridad instanceof PrioridadOperacional) {
            throw new DomainException('La prioridad de la tarea no es válida.');
        }
        $this->emergencias->validarNuevaLabor(
            $plan,
            $prioridad,
            $camaraOrigen?->id,
            $camaraDestino?->id,
        );
        if (isset($datos['contexto']) && ! is_array($datos['contexto'])) {
            throw new DomainException('El contexto de la tarea debe ser una estructura válida.');
        }

        $tarea = TareaMovimiento::create([
            'plan_operacional_id' => $plan->id,
            'secuencia' => $secuencia,
            'tipo_movimiento' => $tipo,
            'estado' => EstadoTareaMovimiento::Pendiente,
            'prioridad' => $prioridad,
            'folio_id' => $folio->id,
            'camara_origen_id' => $camaraOrigen?->id,
            'posicion_origen_id' => $posicionOrigen?->id,
            'camara_destino_id' => $camaraDestino?->id,
            'posicion_destino_id' => $posicionDestino?->id,
            'instruccion' => filled($datos['instruccion'] ?? null)
                ? trim((string) $datos['instruccion'])
                : null,
            'contexto' => ($datos['contexto'] ?? []) !== [] ? $datos['contexto'] : null,
        ]);

        $tipoPaso = $datos['tipo_paso_maniobra']
            ?? TipoPasoManiobra::MovimientoPermanente;
        if (is_string($tipoPaso)) {
            $tipoPaso = TipoPasoManiobra::tryFrom($tipoPaso);
        }
        if (! $tipoPaso instanceof TipoPasoManiobra) {
            throw new DomainException('La tarea requiere un tipo de paso de maniobra válido.');
        }

        return $this->maniobras->registrarUnitaria(
            $tarea,
            $tipoPaso,
            $datos['candidate_key'] ?? data_get($datos, 'contexto.candidate_key'),
        );
    }

    private function validarPlanAsignable(PlanOperacional $plan): void
    {
        if (! $plan->temporada?->activa) {
            throw new DomainException('La tarea no pertenece a la temporada activa.');
        }
        if ($plan->estado === EstadoPlanOperacional::Pausado) {
            throw new DomainException('El plan se encuentra pausado por supervisión.');
        }
        if ($plan->estado->esFinal()) {
            throw new DomainException('El plan ya se encuentra finalizado.');
        }
    }

    private function validarActor(User $usuario, Dispositivo $dispositivo): void
    {
        if (! User::query()->whereKey($usuario->id)->where('activo', true)->exists()
            || ! Dispositivo::query()->whereKey($dispositivo->id)->where('activo', true)->exists()) {
            throw new DomainException('El camarero o la tablet no se encuentra activo.');
        }
    }

    private function camara(?string $id): ?Camara
    {
        return $id ? Camara::query()->findOrFail($id) : null;
    }

    private function posicion(?string $id): ?Posicion
    {
        return $id ? Posicion::query()->findOrFail($id) : null;
    }

    private function validarExtremo(
        ?Camara $camara,
        ?Posicion $posicion,
        string $nombre,
        bool $permitirCamaraSinPosicion = false,
    ): void {
        if ($posicion && ! $camara) {
            throw new DomainException("La posición de {$nombre} requiere indicar su cámara.");
        }
        if (! $permitirCamaraSinPosicion && $camara && ! $posicion) {
            throw new DomainException("El extremo de {$nombre} debe incluir cámara y posición.");
        }
        if ($camara && $posicion && $posicion->camara_id !== $camara->id) {
            throw new DomainException("La posición de {$nombre} no pertenece a la cámara indicada.");
        }
        if ($camara
            && ($camara->contenido !== ContenidoCamara::Productos
                || $camara->estado !== EstadoCamara::Activa
                || ($posicion && $posicion->estado !== EstadoPosicion::Activa))) {
            throw new DomainException("El extremo de {$nombre} no está habilitado para pallets de producto terminado.");
        }
    }

    private function validarEstructuraBatch(
        TipoMovimiento $tipo,
        ?Camara $camaraOrigen,
        ?Posicion $posicionOrigen,
        ?Camara $camaraDestino,
        ?Posicion $posicionDestino,
    ): void {
        $origen = $camaraOrigen !== null && $posicionOrigen !== null;
        $destino = $camaraDestino !== null && $posicionDestino !== null;
        $valida = match ($tipo) {
            TipoMovimiento::UbicacionInicial => ! $origen && $destino,
            TipoMovimiento::Reubicacion => $origen && $destino
                && $camaraOrigen->id === $camaraDestino->id
                && $posicionOrigen->id !== $posicionDestino->id,
            TipoMovimiento::TrasladoEntreCamaras => $origen && $destino
                && $camaraOrigen->id !== $camaraDestino->id,
            TipoMovimiento::Retiro => $origen && ! $destino,
            TipoMovimiento::Reversion => false,
        };

        if (! $valida) {
            throw new DomainException('El origen y destino no corresponden al tipo de tarea.');
        }
    }

    private function validarEstructuraRolling(
        TipoMovimiento $tipo,
        ?Camara $camaraOrigen,
        ?Posicion $posicionOrigen,
        ?Camara $camaraDestino,
        ?Posicion $posicionDestino,
    ): void {
        $origen = $camaraOrigen !== null && $posicionOrigen !== null;
        $sinOrigen = $camaraOrigen === null && $posicionOrigen === null;
        $sinDestino = $camaraDestino === null && $posicionDestino === null;
        $valida = match ($tipo) {
            TipoMovimiento::UbicacionInicial => $sinOrigen,
            TipoMovimiento::Reubicacion => $origen
                && ($sinDestino || ($camaraDestino?->id === $camaraOrigen->id
                    && $posicionDestino?->id !== $posicionOrigen->id)),
            TipoMovimiento::TrasladoEntreCamaras => $origen
                && ($sinDestino || $camaraDestino?->id !== $camaraOrigen->id),
            TipoMovimiento::Retiro => $origen && $sinDestino,
            TipoMovimiento::Reversion => false,
        };

        if (! $valida) {
            throw new DomainException('El objetivo rolling no corresponde al tipo de movimiento de la tarea.');
        }
    }

    private function validarDestinoConcentracion(TareaMovimiento $tarea, Posicion $posicion): void
    {
        $contexto = $tarea->contexto ?? [];
        $camaraObjetivoId = $contexto['camara_objetivo_id'] ?? $tarea->camara_destino_id;
        if (! $camaraObjetivoId || $posicion->camara_id !== $camaraObjetivoId) {
            throw new ConflictoOperacion(
                'La posición propuesta no pertenece a la cámara objetivo de concentración.',
            );
        }

        $puntos = $contexto['concentracion_puntos'] ?? [];
        if (! is_array($puntos) || $puntos === []) {
            return;
        }

        $vecina = collect($puntos)->contains(function (mixed $punto) use ($posicion): bool {
            if (! is_array($punto)
                || ! isset($punto['banda'], $punto['posicion'], $punto['nivel'])) {
                return false;
            }
            if ((int) $punto['nivel'] !== (int) $posicion->nivel) {
                return false;
            }

            $diferenciaBanda = abs((int) $punto['banda'] - (int) $posicion->banda);
            $diferenciaPosicion = abs((int) $punto['posicion'] - (int) $posicion->posicion);

            return ($diferenciaBanda === 0 && $diferenciaPosicion === 1)
                || ($diferenciaBanda === 1 && $diferenciaPosicion <= 1);
        });

        if (! $vecina) {
            throw new ConflictoOperacion(
                'La posición propuesta no amplía físicamente el grupo principal de la carga.',
            );
        }
    }

    private function validarDestinoRetorno(TareaMovimiento $tarea, Posicion $posicion): void
    {
        $contexto = $tarea->contexto ?? [];
        if ($posicion->camara_id !== ($contexto['camara_retorno_id'] ?? null)
            || (int) $posicion->banda !== (int) ($contexto['banda_retorno'] ?? 0)
            || (int) $posicion->nivel !== (int) ($contexto['nivel_retorno'] ?? 0)
            || (int) $posicion->posicion !== (int) ($contexto['profundidad_resultante'] ?? 0)) {
            throw new ConflictoOperacion(
                'El retorno debe mantener la banda, nivel y profundidad resultante de la maniobra.',
            );
        }
    }

    private function cargar(PlanOperacional $plan): PlanOperacional
    {
        return $plan->load([
            'temporada:id,codigo,nombre,activa',
            'creadoPor:id,name',
            'iniciadoPor:id,name',
            'tareas' => fn ($consulta) => $consulta->with($this->relacionesTarea()),
        ]);
    }

    private function horizonte(PlanOperacional $plan): string
    {
        return ($plan->contexto ?? [])['planner_horizon']
            ?? config('planificador.horizon');
    }

    private function cargarTarea(TareaMovimiento $tarea): TareaMovimiento
    {
        return $tarea->load([
            ...$this->relacionesTarea(),
            'planOperacional:id,temporada_id,tipo,estado,prioridad,titulo,version,contexto',
        ]);
    }

    /** @return array<int, string> */
    private function relacionesTarea(): array
    {
        return [
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
            'maniobraOperacional.pasos:id,maniobra_operacional_id,secuencia_maniobra,tipo_movimiento,tipo_paso_maniobra,estado,folio_id,camara_origen_id,posicion_origen_id,camara_destino_id,posicion_destino_id,instruccion,contexto',
            'maniobraOperacional.pasos.folio:id,numero_folio',
            'maniobraOperacional.pasos.camaraOrigen:id,nombre',
            'maniobraOperacional.pasos.posicionOrigen:id,camara_id,etiqueta,banda,posicion,nivel',
            'maniobraOperacional.pasos.camaraDestino:id,nombre',
            'maniobraOperacional.pasos.posicionDestino:id,camara_id,etiqueta,banda,posicion,nivel',
            'maniobraOperacional.custodiasTemporales:id,maniobra_operacional_id,folio_id,camara_origen_id,posicion_origen_id,estado,extraido_at',
            'maniobraOperacional.custodiasTemporales.folio:id,numero_folio',
            'maniobraOperacional.custodiasTemporales.camaraOrigen:id,nombre',
            'maniobraOperacional.custodiasTemporales.posicionOrigen:id,camara_id,etiqueta,banda,posicion,nivel',
        ];
    }
}
