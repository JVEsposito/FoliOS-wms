<?php

namespace App\Services\Estiba;

use App\Enums\AccionResolucionDiscrepancia;
use App\Enums\EstadoCustodiaTemporal;
use App\Enums\EstadoDiscrepanciaManiobra;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoPosicion;
use App\Enums\EstadoReservaTareaMovimiento;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPasoManiobra;
use App\Exceptions\ConflictoOperacion;
use App\Models\Carga;
use App\Models\CustodiaTemporalManiobra;
use App\Models\DiscrepanciaManiobra;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\ManiobraOperacional;
use App\Models\Movimiento;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ReservaBandaManiobra;
use App\Models\ReservaTareaMovimiento;
use App\Models\TareaMovimiento;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Camaras\InterbloqueoEvacuacionEmergencia;
use App\Services\Cargas\ServicioPlanConcentracionCarga;
use App\Services\Planificador\ServicioArbitrajeManiobras;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioManiobrasOperacionales
{
    private const CONTRATO_UNITARIO = 'maniobra_unitaria_v1';

    public function __construct(
        private readonly ServicioReservasTareasMovimiento $reservas,
        private readonly InterbloqueoEvacuacionEmergencia $emergencias,
        private readonly ServicioReplanificacionDiscrepancia $replanificador,
        private readonly ServicioArbitrajeManiobras $arbitraje,
    ) {}

    /**
     * Persiste una solución física completa. Los pasos posteriores permanecen
     * bloqueados hasta que el movimiento anterior se confirme.
     *
     * @param  array{
     *   candidate_key:string,
     *   titulo:string,
     *   motivo?:string|null,
     *   beneficio_estimado?:int,
     *   beneficio_principal_estimado?:int,
     *   riesgo_operacional?:int,
     *   contexto?:array<string,mixed>,
     *   bloqueos_banda?:array<int,array{camara_id:string,banda:int,nivel:int}>,
     *   pasos:array<int,array<string,mixed>>
     * }  $datos
     */
    public function crearCerrada(
        PlanOperacional $plan,
        User $usuario,
        array $datos,
    ): ManiobraOperacional {
        return DB::transaction(function () use ($plan, $usuario, $datos): ManiobraOperacional {
            $plan = PlanOperacional::query()->lockForUpdate()->findOrFail($plan->id);
            $pasos = array_values($datos['pasos'] ?? []);
            $this->validarCierre($pasos);
            if (! User::query()->whereKey($usuario->id)->where('activo', true)->exists()) {
                throw new DomainException('El usuario que origina la maniobra no se encuentra activo.');
            }

            $clave = trim((string) ($datos['candidate_key'] ?? ''));
            if ($clave === '' || mb_strlen($clave) > 190) {
                throw new DomainException('La maniobra requiere una clave candidata válida.');
            }
            if (ManiobraOperacional::query()
                ->where('plan_operacional_id', $plan->id)
                ->where('candidate_key', $clave)
                ->whereIn('estado', [
                    EstadoManiobraOperacional::Pendiente->value,
                    EstadoManiobraOperacional::EnEjecucion->value,
                    EstadoManiobraOperacional::PausadaDiscrepancia->value,
                    EstadoManiobraOperacional::PausadaSupervision->value,
                ])
                ->exists()) {
                throw new ConflictoOperacion('La maniobra candidata ya fue publicada.');
            }

            $folioIds = collect($pasos)->pluck('folio_id')->unique()->values();
            $folios = Folio::query()
                ->whereIn('id', $folioIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($folios->count() !== $folioIds->count()
                || $folios->contains(fn (Folio $folio): bool => $folio->temporada_id !== $plan->temporada_id
                    || ! $folio->activo
                    || $folio->tipo_bulto !== TipoBulto::Pallet)) {
                throw new DomainException('La maniobra solo admite pallets completos activos de la temporada.');
            }

            $conflicto = TareaMovimiento::query()
                ->whereIn('folio_id', $folioIds)
                ->whereIn('estado', $this->estadosActivosTarea())
                ->lockForUpdate()
                ->exists();
            if ($conflicto) {
                throw new ConflictoOperacion('Un pallet de la maniobra ya posee otra labor activa.');
            }

            $maniobra = ManiobraOperacional::create([
                'plan_operacional_id' => $plan->id,
                'creado_por_user_id' => $usuario->id,
                'estado' => EstadoManiobraOperacional::Pendiente,
                'prioridad' => $plan->prioridad,
                'candidate_key' => $clave,
                'titulo' => Str::limit(trim((string) ($datos['titulo'] ?? $plan->titulo)), 180, ''),
                'motivo' => filled($datos['motivo'] ?? null)
                    ? trim((string) $datos['motivo'])
                    : null,
                'secuencia_actual' => 1,
                'costo_movimientos' => count($pasos),
                'beneficio_estimado' => (int) ($datos['beneficio_estimado'] ?? 0),
                'riesgo_operacional' => max(0, (int) ($datos['riesgo_operacional'] ?? 0)),
                'contexto' => ($datos['contexto'] ?? []) ?: null,
            ]);
            $maniobra->objetivos()->attach($plan->id, [
                'es_principal' => true,
                'beneficio_estimado' => (int) (
                    $datos['beneficio_principal_estimado']
                    ?? $datos['beneficio_estimado']
                    ?? 0
                ),
                'contexto' => json_encode([
                    'candidate_key' => $clave,
                    'tipo_objetivo' => $plan->tipo->value,
                ], JSON_THROW_ON_ERROR),
            ]);

            $secuenciaPlan = (int) TareaMovimiento::query()
                ->where('plan_operacional_id', $plan->id)
                ->lockForUpdate()
                ->max('secuencia');
            foreach ($pasos as $indice => $paso) {
                $tipo = $paso['tipo_movimiento'] ?? null;
                $tipoPaso = $paso['tipo_paso_maniobra'] ?? null;
                if (! $tipo instanceof TipoMovimiento || ! $tipoPaso instanceof TipoPasoManiobra) {
                    throw new DomainException('Cada paso requiere un tipo físico y un tipo de maniobra válidos.');
                }
                $prioridad = $paso['prioridad'] ?? $plan->prioridad;
                if (is_string($prioridad)) {
                    $prioridad = PrioridadOperacional::tryFrom($prioridad);
                }
                if (! $prioridad instanceof PrioridadOperacional) {
                    throw new DomainException('Cada paso requiere una prioridad operacional válida.');
                }
                $this->emergencias->validarNuevaLabor(
                    $plan,
                    $prioridad,
                    $paso['camara_origen_id'] ?? null,
                    $paso['camara_destino_id'] ?? null,
                );

                TareaMovimiento::create([
                    'plan_operacional_id' => $plan->id,
                    'maniobra_operacional_id' => $maniobra->id,
                    'secuencia' => ++$secuenciaPlan,
                    'secuencia_maniobra' => $indice + 1,
                    'tipo_movimiento' => $tipo,
                    'tipo_paso_maniobra' => $tipoPaso,
                    'estado' => $indice === 0
                        ? EstadoTareaMovimiento::Pendiente
                        : EstadoTareaMovimiento::Bloqueada,
                    'prioridad' => $prioridad,
                    'folio_id' => $paso['folio_id'],
                    'camara_origen_id' => $paso['camara_origen_id'] ?? null,
                    'posicion_origen_id' => $paso['posicion_origen_id'] ?? null,
                    'camara_destino_id' => $paso['camara_destino_id'] ?? null,
                    'posicion_destino_id' => $paso['posicion_destino_id'] ?? null,
                    'instruccion' => filled($paso['instruccion'] ?? null)
                        ? trim((string) $paso['instruccion'])
                        : null,
                    'contexto' => [
                        ...($paso['contexto'] ?? []),
                        'candidate_key' => $clave,
                        'maniobra_cerrada' => true,
                        'paso' => $indice + 1,
                        'pasos_totales' => count($pasos),
                    ],
                ]);
            }

            foreach ($datos['bloqueos_banda'] ?? [] as $bloqueo) {
                ReservaBandaManiobra::create([
                    'maniobra_operacional_id' => $maniobra->id,
                    'camara_id' => $bloqueo['camara_id'],
                    'banda' => (int) $bloqueo['banda'],
                    'nivel' => (int) $bloqueo['nivel'],
                    'reservada_at' => now(),
                ]);
            }

            $plan->update(['version' => $plan->version + 1]);

            return $this->cargar($maniobra);
        }, attempts: 3);
    }

    /**
     * Incorpora una tarea física simple al mismo contrato operacional de las
     * maniobras cerradas. La fila de tarea se conserva para no alterar las
     * rutas ni los consumidores históricos del planificador.
     */
    public function registrarUnitaria(
        TareaMovimiento $tarea,
        TipoPasoManiobra $tipoPaso = TipoPasoManiobra::MovimientoPermanente,
        ?string $candidateKey = null,
    ): TareaMovimiento {
        if (! in_array($tipoPaso, [
            TipoPasoManiobra::MovimientoPermanente,
            TipoPasoManiobra::EntregaAnden,
        ], true)) {
            throw new DomainException(
                'Una maniobra unitaria debe resolver un movimiento permanente o una entrega a andén.',
            );
        }

        return DB::transaction(function () use ($tarea, $tipoPaso, $candidateKey): TareaMovimiento {
            $tarea = TareaMovimiento::query()
                ->with(['planOperacional', 'folio'])
                ->lockForUpdate()
                ->findOrFail($tarea->id);

            if ($tarea->maniobra_operacional_id) {
                if ($tarea->secuencia_maniobra !== 1
                    || $tarea->tipo_paso_maniobra !== $tipoPaso) {
                    throw new ConflictoOperacion(
                        'La tarea ya pertenece a una maniobra con otro contrato físico.',
                    );
                }

                return $tarea->refresh();
            }
            if ($tarea->estado !== EstadoTareaMovimiento::Pendiente) {
                throw new ConflictoOperacion(
                    'Solo una tarea pendiente puede incorporarse como maniobra unitaria.',
                );
            }
            if ($tipoPaso === TipoPasoManiobra::EntregaAnden
                && $tarea->tipo_movimiento !== TipoMovimiento::Retiro) {
                throw new DomainException(
                    'Una entrega a andén debe retirar el pallet desde su origen físico o lógico.',
                );
            }

            $plan = $tarea->planOperacional;
            $contextoTarea = $tarea->contexto ?? [];
            $clave = trim((string) (
                $candidateKey
                ?? $contextoTarea['candidate_key']
                ?? "unitaria:{$tarea->id}"
            ));
            if ($clave === '' || mb_strlen($clave) > 190) {
                throw new DomainException('La maniobra unitaria requiere una clave candidata válida.');
            }

            $titulo = filled($tarea->instruccion)
                ? trim((string) $tarea->instruccion)
                : "{$plan->titulo} · {$tarea->folio->numero_folio}";
            $maniobra = ManiobraOperacional::create([
                'plan_operacional_id' => $plan->id,
                'creado_por_user_id' => $plan->creado_por_user_id,
                'estado' => EstadoManiobraOperacional::Pendiente,
                'prioridad' => $tarea->prioridad,
                'candidate_key' => $clave,
                'titulo' => Str::limit($titulo, 180, ''),
                'motivo' => $plan->motivo,
                'secuencia_actual' => 1,
                'costo_movimientos' => 1,
                'beneficio_estimado' => (int) ($contextoTarea['beneficio_estimado'] ?? 0),
                'riesgo_operacional' => max(
                    0,
                    (int) ($contextoTarea['riesgo_operacional'] ?? 0),
                ),
                'contexto' => [
                    'contrato' => self::CONTRATO_UNITARIO,
                    'tipo_objetivo' => $plan->tipo->value,
                    'tarea_origen_id' => $tarea->id,
                    'folio_id' => $tarea->folio_id,
                ],
            ]);
            $maniobra->objetivos()->attach($plan->id, [
                'es_principal' => true,
                'beneficio_estimado' => (int) ($contextoTarea['beneficio_estimado'] ?? 0),
                'contexto' => json_encode([
                    'candidate_key' => $clave,
                    'tipo_objetivo' => $plan->tipo->value,
                    'contrato' => self::CONTRATO_UNITARIO,
                ], JSON_THROW_ON_ERROR),
            ]);

            $tarea->update([
                'maniobra_operacional_id' => $maniobra->id,
                'secuencia_maniobra' => 1,
                'tipo_paso_maniobra' => $tipoPaso,
                'contexto' => [
                    ...$contextoTarea,
                    'candidate_key' => $clave,
                    'maniobra_cerrada' => true,
                    'maniobra_unitaria' => true,
                    'paso' => 1,
                    'pasos_totales' => 1,
                ],
            ]);

            return $tarea->refresh();
        }, attempts: 3);
    }

    /**
     * Declara que una maniobra ya publicada resuelve además otro objetivo
     * operacional. La maniobra conserva un único dueño físico, pero agrega
     * el beneficio, la prioridad y la trazabilidad del plan secundario.
     *
     * @param  array<string, mixed>  $contexto
     */
    public function vincularObjetivo(
        ManiobraOperacional $maniobra,
        PlanOperacional $objetivo,
        int $beneficioEstimado,
        array $contexto = [],
    ): ManiobraOperacional {
        return DB::transaction(function () use (
            $maniobra,
            $objetivo,
            $beneficioEstimado,
            $contexto,
        ): ManiobraOperacional {
            $maniobra = ManiobraOperacional::query()
                ->lockForUpdate()
                ->findOrFail($maniobra->id);
            $objetivo = PlanOperacional::query()
                ->lockForUpdate()
                ->findOrFail($objetivo->id);

            if ($maniobra->estado !== EstadoManiobraOperacional::Pendiente) {
                throw new DomainException(
                    'Los objetivos de una maniobra solo pueden consolidarse antes de asumirla.',
                );
            }
            $planPrincipal = PlanOperacional::query()->findOrFail(
                $maniobra->plan_operacional_id,
            );
            if ($objetivo->temporada_id !== $planPrincipal->temporada_id) {
                throw new DomainException(
                    'Una maniobra no puede consolidar objetivos de temporadas distintas.',
                );
            }
            if (! in_array($objetivo->estado, [
                EstadoPlanOperacional::Programado,
                EstadoPlanOperacional::EnEjecucion,
            ], true)) {
                throw new DomainException(
                    'Una maniobra solo puede incorporar un objetivo operativo vigente.',
                );
            }
            if ($maniobra->objetivos()->whereKey($objetivo->id)->exists()) {
                return $this->cargar($maniobra);
            }

            $maniobra->objetivos()->attach($objetivo->id, [
                'es_principal' => false,
                'beneficio_estimado' => max(0, $beneficioEstimado),
                'contexto' => $contexto !== []
                    ? json_encode($contexto, JSON_THROW_ON_ERROR)
                    : null,
            ]);
            $prioridad = $maniobra->objetivos()
                ->get(['prioridad'])
                ->map(fn (PlanOperacional $plan): PrioridadOperacional => $plan->prioridad)
                ->sortByDesc(fn (PrioridadOperacional $valor): int => $valor->peso())
                ->first()
                ?? $maniobra->prioridad;
            $beneficio = (int) $maniobra->objetivos()
                ->sum('maniobra_objetivos.beneficio_estimado');

            $maniobra->update([
                'prioridad' => $prioridad,
                'beneficio_estimado' => $beneficio,
                'version' => $maniobra->version + 1,
            ]);
            $maniobra->pasos()
                ->whereIn('estado', $this->estadosActivosTarea())
                ->lockForUpdate()
                ->get()
                ->each(function (TareaMovimiento $paso) use ($prioridad): void {
                    if ($paso->prioridad->peso() >= $prioridad->peso()) {
                        return;
                    }
                    $paso->update([
                        'prioridad' => $prioridad,
                        'version' => $paso->version + 1,
                    ]);
                });

            return $this->cargar($maniobra->refresh());
        }, attempts: 3);
    }

    public function asumirPaso(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
    ): TareaMovimiento {
        $maniobra = ManiobraOperacional::query()
            ->with('reservasBandas')
            ->lockForUpdate()
            ->findOrFail($tarea->maniobra_operacional_id);
        $tarea = TareaMovimiento::query()
            ->with('planOperacional')
            ->lockForUpdate()
            ->findOrFail($tarea->id);

        $this->validarPasoActualInterno($maniobra, $tarea);
        if (in_array($maniobra->estado, [
            EstadoManiobraOperacional::PausadaDiscrepancia,
            EstadoManiobraOperacional::PausadaSupervision,
        ], true)) {
            throw new ConflictoOperacion('La maniobra está pausada y no puede ser asumida.');
        }
        if ($maniobra->responsable_user_id !== null
            && ($maniobra->responsable_user_id !== $usuario->id
                || $maniobra->dispositivo_id !== $dispositivo->id)) {
            throw new ConflictoOperacion('La maniobra ya pertenece a otro camarero o tablet.');
        }
        $this->arbitraje->validarAsumible($maniobra);
        $esManiobraUnitaria = ($maniobra->contexto['contrato'] ?? null)
            === self::CONTRATO_UNITARIO;
        if ($maniobra->estado !== EstadoManiobraOperacional::EnEjecucion
            && ! $esManiobraUnitaria
            && ManiobraOperacional::query()
                ->whereKeyNot($maniobra->id)
                ->where('estado', EstadoManiobraOperacional::EnEjecucion->value)
                ->lockForUpdate()
                ->get(['id', 'contexto'])
                ->reject(static function (ManiobraOperacional $enEjecucion): bool {
                    return ($enEjecucion->contexto['contrato'] ?? null)
                        === self::CONTRATO_UNITARIO;
                })
                ->count() >= (int) config('planificador.maniobras_simultaneas_max', 3)) {
            throw new ConflictoOperacion(
                'Ya existen tres maniobras asumidas; la cuarta debe permanecer como alternativa.',
            );
        }

        $this->bloquearBandas($maniobra);
        $ahora = now();
        $maniobra->update([
            'estado' => EstadoManiobraOperacional::EnEjecucion,
            'responsable_user_id' => $usuario->id,
            'dispositivo_id' => $dispositivo->id,
            'asumida_at' => $maniobra->asumida_at ?? $ahora,
            'iniciada_at' => $maniobra->iniciada_at ?? $ahora,
            'version' => $maniobra->version + 1,
        ]);
        $this->reservas->asumir($tarea, $usuario, $dispositivo);
        $this->materializarDestinoPrecalculado($tarea->refresh(), $usuario, $dispositivo);
        $maniobra->objetivos()
            ->whereKeyNot($maniobra->plan_operacional_id)
            ->where('estado', EstadoPlanOperacional::Programado->value)
            ->lockForUpdate()
            ->get()
            ->each(function (PlanOperacional $objetivo) use ($usuario, $ahora): void {
                $objetivo->update([
                    'estado' => EstadoPlanOperacional::EnEjecucion,
                    'iniciado_por_user_id' => $usuario->id,
                    'iniciado_at' => $ahora,
                    'version' => $objetivo->version + 1,
                ]);
            });

        return $tarea->refresh();
    }

    /**
     * Cierra una entrega física confirmada por un flujo que no genera un
     * Movimiento de estiba, como Prefrío directo a andén.
     */
    public function completarPasoSinMovimiento(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
    ): TareaMovimiento {
        return DB::transaction(function () use ($tarea, $usuario, $dispositivo): TareaMovimiento {
            $tarea = TareaMovimiento::query()->lockForUpdate()->findOrFail($tarea->id);

            // Compatibilidad explícita con labores activas creadas antes de
            // que las tareas simples adoptaran el contrato de maniobra.
            if (! $tarea->maniobra_operacional_id) {
                $tarea->update([
                    'estado' => EstadoTareaMovimiento::Completada,
                    'completada_at' => now(),
                    'version' => $tarea->version + 1,
                ]);

                return $tarea->refresh();
            }

            $maniobra = ManiobraOperacional::query()
                ->lockForUpdate()
                ->findOrFail($tarea->maniobra_operacional_id);
            $this->validarPasoActualInterno($maniobra, $tarea);
            if ($maniobra->costo_movimientos !== 1
                || $tarea->tipo_paso_maniobra !== TipoPasoManiobra::EntregaAnden) {
                throw new DomainException(
                    'Solo una entrega unitaria a andén puede cerrarse sin movimiento de cámara.',
                );
            }
            if ($tarea->estado !== EstadoTareaMovimiento::EnProceso
                || $tarea->responsable_user_id !== $usuario->id
                || $tarea->dispositivo_id !== $dispositivo->id
                || $maniobra->estado !== EstadoManiobraOperacional::EnEjecucion
                || $maniobra->responsable_user_id !== $usuario->id
                || $maniobra->dispositivo_id !== $dispositivo->id) {
                throw new ConflictoOperacion(
                    'La entrega debe estar en ejecución por el camarero y la tablet que asumieron la maniobra.',
                );
            }
            if ($maniobra->custodiasTemporales()
                ->where('estado', EstadoCustodiaTemporal::Activa->value)
                ->lockForUpdate()
                ->exists()) {
                throw new DomainException(
                    'La maniobra no puede cerrar con pallets en custodia temporal.',
                );
            }

            $ahora = now();
            $tarea->update([
                'estado' => EstadoTareaMovimiento::Completada,
                'completada_at' => $ahora,
                'version' => $tarea->version + 1,
            ]);
            $this->liberarBandas($maniobra, 'Maniobra física completada sin movimiento de cámara.');
            $maniobra->update([
                'estado' => EstadoManiobraOperacional::Completada,
                'completada_at' => $ahora,
                'secuencia_actual' => 1,
                'version' => $maniobra->version + 1,
            ]);

            return $tarea->refresh();
        }, attempts: 3);
    }

    public function liberarAntesDeIniciar(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
    ): void {
        $maniobra = ManiobraOperacional::query()->lockForUpdate()->findOrFail(
            $tarea->maniobra_operacional_id,
        );
        $this->validarPasoActualInterno($maniobra, $tarea);
        if ($tarea->estado === EstadoTareaMovimiento::EnProceso
            || $maniobra->custodiasTemporales()
                ->where('estado', EstadoCustodiaTemporal::Activa->value)
                ->exists()) {
            throw new ConflictoOperacion(
                'La maniobra ya modificó la realidad física y no puede liberarse.',
            );
        }

        $this->reservas->liberar($tarea, $usuario, $dispositivo);
        $this->liberarBandas($maniobra, 'Maniobra devuelta a la bandeja antes de iniciar.');
        $poseePrefijoFisico = $maniobra->pasos()
            ->where('estado', EstadoTareaMovimiento::Completada->value)
            ->lockForUpdate()
            ->exists();
        $maniobra->update([
            'estado' => EstadoManiobraOperacional::Pendiente,
            'responsable_user_id' => null,
            'dispositivo_id' => null,
            'asumida_at' => $poseePrefijoFisico ? $maniobra->asumida_at : null,
            'iniciada_at' => $poseePrefijoFisico ? $maniobra->iniciada_at : null,
            'version' => $maniobra->version + 1,
        ]);
    }

    public function avanzarTrasMovimiento(TareaMovimiento $tarea, Movimiento $movimiento): void
    {
        if (! $tarea->maniobra_operacional_id) {
            return;
        }

        $maniobra = ManiobraOperacional::query()->lockForUpdate()->findOrFail(
            $tarea->maniobra_operacional_id,
        );
        $tarea = TareaMovimiento::query()->lockForUpdate()->findOrFail($tarea->id);
        $this->registrarCustodia($maniobra, $tarea, $movimiento);

        if ($maniobra->estado === EstadoManiobraOperacional::PausadaDiscrepancia) {
            return;
        }

        $siguiente = $maniobra->pasos()
            ->where('secuencia_maniobra', '>', $tarea->secuencia_maniobra)
            ->orderBy('secuencia_maniobra')
            ->lockForUpdate()
            ->first();
        if (! $siguiente) {
            if ($maniobra->custodiasTemporales()
                ->where('estado', EstadoCustodiaTemporal::Activa->value)
                ->lockForUpdate()
                ->exists()) {
                throw new DomainException(
                    'La maniobra no puede cerrar con pallets extraídos temporalmente.',
                );
            }

            $this->liberarBandas($maniobra, 'Maniobra física completada.');
            $maniobra->update([
                'estado' => EstadoManiobraOperacional::Completada,
                'completada_at' => now(),
                'secuencia_actual' => $tarea->secuencia_maniobra,
                'version' => $maniobra->version + 1,
            ]);
            $plan = $maniobra->planOperacional()->first();
            $cargaId = $plan?->referencia_tipo === 'carga_concentracion'
                ? $plan->referencia_id
                : null;
            if ($cargaId) {
                $usuarioId = $movimiento->user_id;
                DB::afterCommit(function () use ($cargaId, $usuarioId): void {
                    $carga = Carga::query()->find($cargaId);
                    $usuario = User::query()->find($usuarioId);
                    if ($carga && $usuario) {
                        app(ServicioPlanConcentracionCarga::class)->sincronizar($carga, $usuario);
                    }
                });
            }

            return;
        }

        $siguiente->update([
            'estado' => EstadoTareaMovimiento::Pendiente,
            'version' => $siguiente->version + 1,
        ]);
        $maniobra->update([
            'secuencia_actual' => $siguiente->secuencia_maniobra,
            'version' => $maniobra->version + 1,
        ]);

        $usuario = User::query()->findOrFail($maniobra->responsable_user_id);
        $dispositivo = Dispositivo::query()->findOrFail($maniobra->dispositivo_id);
        $siguiente = $siguiente->refresh();
        $this->reservas->asumir($siguiente, $usuario, $dispositivo);
        $this->materializarDestinoPrecalculado(
            $siguiente->refresh(),
            $usuario,
            $dispositivo,
        );
    }

    public function reportarDiscrepancia(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
        string $tipo,
        ?string $detalle = null,
    ): DiscrepanciaManiobra {
        return DB::transaction(function () use (
            $tarea,
            $usuario,
            $dispositivo,
            $tipo,
            $detalle,
        ): DiscrepanciaManiobra {
            $tarea = TareaMovimiento::query()->lockForUpdate()->findOrFail($tarea->id);
            if (! $tarea->maniobra_operacional_id) {
                throw new DomainException('La tarea no pertenece a una maniobra física.');
            }
            $maniobra = ManiobraOperacional::query()->lockForUpdate()->findOrFail(
                $tarea->maniobra_operacional_id,
            );
            $this->validarPasoActualInterno($maniobra, $tarea);
            if ($maniobra->estado === EstadoManiobraOperacional::PausadaDiscrepancia) {
                throw new ConflictoOperacion('La maniobra ya se encuentra pausada.');
            }
            if ($maniobra->responsable_user_id !== $usuario->id
                || $maniobra->dispositivo_id !== $dispositivo->id) {
                throw new ConflictoOperacion('La maniobra pertenece a otro camarero o tablet.');
            }

            $discrepancia = DiscrepanciaManiobra::create([
                'maniobra_operacional_id' => $maniobra->id,
                'tarea_movimiento_id' => $tarea->id,
                'folio_id' => $tarea->folio_id,
                'tipo' => Str::limit(trim($tipo), 50, ''),
                'detalle' => filled($detalle) ? trim((string) $detalle) : null,
                'estado' => EstadoDiscrepanciaManiobra::Abierta,
                'reportada_por_user_id' => $usuario->id,
                'dispositivo_id' => $dispositivo->id,
                'reportada_at' => now(),
            ]);
            $maniobra->update([
                'estado' => EstadoManiobraOperacional::PausadaDiscrepancia,
                'pausada_at' => now(),
                'version' => $maniobra->version + 1,
            ]);

            if ($tarea->estado !== EstadoTareaMovimiento::EnProceso) {
                $this->reservas->liberarParaReplanificacion(
                    $tarea,
                    'Discrepancia física reportada.',
                );
                $tarea->update([
                    'estado' => EstadoTareaMovimiento::Bloqueada,
                    'version' => $tarea->version + 1,
                ]);
                $poseeCustodiaActiva = $maniobra->custodiasTemporales()
                    ->where('estado', EstadoCustodiaTemporal::Activa->value)
                    ->lockForUpdate()
                    ->exists();
                if (! $poseeCustodiaActiva) {
                    $this->liberarBandas(
                        $maniobra,
                        'Maniobra pausada antes de modificar la realidad física.',
                    );
                }
            }

            return $discrepancia;
        }, attempts: 3);
    }

    public function resolverDiscrepancia(
        DiscrepanciaManiobra $discrepancia,
        User $supervisor,
        AccionResolucionDiscrepancia $accion,
        int $versionManiobra,
        string $resolucion,
    ): DiscrepanciaManiobra {
        return DB::transaction(function () use (
            $discrepancia,
            $supervisor,
            $accion,
            $versionManiobra,
            $resolucion,
        ): DiscrepanciaManiobra {
            $referencia = DiscrepanciaManiobra::query()->findOrFail($discrepancia->id);
            $tarea = TareaMovimiento::query()
                ->lockForUpdate()
                ->findOrFail($referencia->tarea_movimiento_id);
            $maniobra = ManiobraOperacional::query()
                ->with('planOperacional.temporada')
                ->lockForUpdate()
                ->findOrFail($referencia->maniobra_operacional_id);
            $discrepancia = DiscrepanciaManiobra::query()
                ->lockForUpdate()
                ->findOrFail($referencia->id);

            if ($discrepancia->estado === EstadoDiscrepanciaManiobra::Resuelta) {
                if ($discrepancia->accion_resolucion === $accion) {
                    return $discrepancia;
                }

                throw new ConflictoOperacion(
                    'La discrepancia ya fue resuelta mediante otra acción.',
                );
            }
            if (! $maniobra->planOperacional?->temporada?->activa) {
                throw new DomainException(
                    'La discrepancia no pertenece a la temporada activa.',
                );
            }
            if ($maniobra->version !== $versionManiobra) {
                throw new ConflictoOperacion(
                    'La maniobra cambió desde que supervisión consultó la discrepancia.',
                );
            }
            if ($tarea->maniobra_operacional_id !== $maniobra->id
                || $discrepancia->maniobra_operacional_id !== $maniobra->id
                || $discrepancia->folio_id !== $tarea->folio_id) {
                throw new DomainException(
                    'La discrepancia perdió correspondencia con su maniobra física.',
                );
            }
            if ($maniobra->estado !== EstadoManiobraOperacional::PausadaDiscrepancia) {
                throw new ConflictoOperacion(
                    'La maniobra ya no se encuentra pausada por discrepancia.',
                );
            }

            match ($accion) {
                AccionResolucionDiscrepancia::ReanudarManiobra => $this
                    ->reanudarTrasDiscrepancia($maniobra),
                AccionResolucionDiscrepancia::ReplanificarSufijo => $this
                    ->replanificarTrasDiscrepancia($maniobra, $supervisor, $discrepancia),
                AccionResolucionDiscrepancia::RetornoSeguro => $this
                    ->retornoSeguroTrasDiscrepancia(
                        $maniobra,
                        $supervisor,
                        $discrepancia,
                    ),
                AccionResolucionDiscrepancia::CancelarManiobra => $this
                    ->cancelarTrasDiscrepancia($maniobra, $supervisor),
            };

            $discrepancia->update([
                'estado' => EstadoDiscrepanciaManiobra::Resuelta,
                'resuelta_por_user_id' => $supervisor->id,
                'resuelta_at' => now(),
                'accion_resolucion' => $accion,
                'resolucion' => trim($resolucion),
            ]);

            return $discrepancia->refresh();
        }, attempts: 3);
    }

    private function reanudarTrasDiscrepancia(ManiobraOperacional $maniobra): void
    {
        $enProceso = $maniobra->pasos()
            ->where('estado', EstadoTareaMovimiento::EnProceso->value)
            ->lockForUpdate()
            ->first();

        if ($enProceso) {
            $reserva = ReservaTareaMovimiento::query()
                ->where('tarea_movimiento_id', $enProceso->id)
                ->where('estado', EstadoReservaTareaMovimiento::Activa->value)
                ->whereNotNull('bloqueo_tarea_id')
                ->lockForUpdate()
                ->first();
            if (! $reserva
                || $maniobra->responsable_user_id !== $reserva->user_id
                || $maniobra->dispositivo_id !== $reserva->dispositivo_id) {
                throw new ConflictoOperacion(
                    'El paso en movimiento perdió su actor o reserva y no puede reanudarse.',
                );
            }

            $maniobra->update([
                'estado' => EstadoManiobraOperacional::EnEjecucion,
                'pausada_at' => null,
                'version' => $maniobra->version + 1,
            ]);

            return;
        }

        $siguiente = $maniobra->pasos()
            ->whereNotIn('estado', [
                EstadoTareaMovimiento::Completada->value,
                EstadoTareaMovimiento::Cancelada->value,
            ])
            ->orderBy('secuencia_maniobra')
            ->lockForUpdate()
            ->first();

        if (! $siguiente) {
            if ($maniobra->custodiasTemporales()
                ->where('estado', EstadoCustodiaTemporal::Activa->value)
                ->lockForUpdate()
                ->exists()) {
                throw new ConflictoOperacion(
                    'La maniobra no posee un paso disponible para cerrar la custodia temporal.',
                );
            }

            $this->liberarBandas($maniobra, 'Discrepancia resuelta al completar el último paso.');
            $maniobra->update([
                'estado' => EstadoManiobraOperacional::Completada,
                'completada_at' => now(),
                'pausada_at' => null,
                'version' => $maniobra->version + 1,
            ]);

            return;
        }

        if (! $this->reservas->liberarParaReplanificacion(
            $siguiente,
            'Discrepancia resuelta: el paso vuelve a la bandeja.',
        )) {
            throw new ConflictoOperacion(
                'El paso actual ya cruzó el punto de no retorno.',
            );
        }

        $siguiente->update([
            'estado' => EstadoTareaMovimiento::Pendiente,
            'responsable_user_id' => null,
            'dispositivo_id' => null,
            'asumida_at' => null,
            'iniciada_at' => null,
            'version' => $siguiente->version + 1,
        ]);
        $custodiaActiva = $maniobra->custodiasTemporales()
            ->where('estado', EstadoCustodiaTemporal::Activa->value)
            ->lockForUpdate()
            ->exists();
        if ($custodiaActiva) {
            // Ya hubo un retiro físico: el siguiente paso conserva la continuidad
            // del mismo actor y su destino, como antes de la pausa.
            $usuario = User::query()->whereKey($maniobra->responsable_user_id)
                ->where('activo', true)->first();
            $dispositivo = Dispositivo::query()->whereKey($maniobra->dispositivo_id)
                ->where('activo', true)->first();
            if (! $usuario || ! $dispositivo) {
                throw new ConflictoOperacion(
                    'La maniobra perdió su camarero o tablet activa y requiere reasignación supervisada.',
                );
            }
            $maniobra->loadMissing('reservasBandas');
            $this->bloquearBandas($maniobra);
            $maniobra->update([
                'estado' => EstadoManiobraOperacional::EnEjecucion,
                'pausada_at' => null,
                'secuencia_actual' => $siguiente->secuencia_maniobra,
                'version' => $maniobra->version + 1,
            ]);
            $this->reservas->asumir($siguiente->refresh(), $usuario, $dispositivo);
            $this->materializarDestinoPrecalculado($siguiente->refresh(), $usuario, $dispositivo);

            return;
        }
        $maniobra->update([
            'estado' => EstadoManiobraOperacional::Pendiente,
            'pausada_at' => null,
            'secuencia_actual' => $siguiente->secuencia_maniobra,
            'responsable_user_id' => null,
            'dispositivo_id' => null,
            'asumida_at' => null,
            'version' => $maniobra->version + 1,
        ]);
    }

    private function replanificarTrasDiscrepancia(
        ManiobraOperacional $maniobra,
        User $supervisor,
        DiscrepanciaManiobra $discrepancia,
    ): void {
        if (! $this->cancelarReversible(
            $maniobra,
            $supervisor,
            'Discrepancia verificada: se descarta y recalcula el sufijo reversible.',
        )) {
            throw new ConflictoOperacion(
                'La maniobra ya modificó la realidad física; sólo puede continuar o ejecutar un retorno seguro.',
            );
        }

        $plan = $maniobra->planOperacional()->firstOrFail();
        $this->replanificador->replanificar($plan, $supervisor, $discrepancia);
    }

    /**
     * Convierte las custodias activas en una maniobra crítica e independiente.
     * El retorno conserva actor, tablet, orden y geometría ya calculada; no
     * inventa destinos desde un estado físico que supervisión acaba de objetar.
     */
    private function retornoSeguroTrasDiscrepancia(
        ManiobraOperacional $maniobra,
        User $supervisor,
        DiscrepanciaManiobra $discrepancia,
    ): void {
        if ($maniobra->pasos()
            ->where('estado', EstadoTareaMovimiento::EnProceso->value)
            ->lockForUpdate()
            ->exists()) {
            throw new ConflictoOperacion(
                'Existe un pallet en movimiento; primero debe cerrarse o reanudarse ese paso físico.',
            );
        }

        $custodias = $maniobra->custodiasTemporales()
            ->where('estado', EstadoCustodiaTemporal::Activa->value)
            ->orderBy('extraido_at')
            ->lockForUpdate()
            ->get();
        if ($custodias->isEmpty()) {
            throw new ConflictoOperacion(
                'La maniobra no posee pallets bajo custodia que requieran retorno seguro.',
            );
        }

        $usuario = $maniobra->responsable_user_id
            ? User::query()
                ->whereKey($maniobra->responsable_user_id)
                ->where('activo', true)
                ->first()
            : null;
        $dispositivo = $maniobra->dispositivo_id
            ? Dispositivo::query()
                ->whereKey($maniobra->dispositivo_id)
                ->where('activo', true)
                ->first()
            : null;
        if (! $usuario || ! $dispositivo) {
            throw new ConflictoOperacion(
                'El retorno seguro requiere conservar el camarero y la tablet responsables de la custodia.',
            );
        }
        if ($custodias->contains(
            fn (CustodiaTemporalManiobra $custodia): bool => $custodia->user_id !== $usuario->id
                || $custodia->dispositivo_id !== $dispositivo->id,
        )) {
            throw new ConflictoOperacion(
                'La custodia activa no coincide con el camarero o la tablet de la maniobra.',
            );
        }

        $retornos = $custodias->map(function (CustodiaTemporalManiobra $custodia) use (
            $maniobra,
        ): array {
            $tarea = $maniobra->pasos()
                ->where('folio_id', $custodia->folio_id)
                ->where('tipo_paso_maniobra', TipoPasoManiobra::RetornoBanda->value)
                ->whereNotIn('estado', [
                    EstadoTareaMovimiento::Completada->value,
                    EstadoTareaMovimiento::Cancelada->value,
                ])
                ->orderBy('secuencia_maniobra')
                ->lockForUpdate()
                ->first();
            if (! $tarea) {
                throw new ConflictoOperacion(
                    'Una custodia activa perdió su retorno calculado; no se generará un destino improvisado.',
                );
            }

            $contexto = $tarea->contexto ?? [];
            $destino = $tarea->posicion_destino_id
                ? Posicion::query()->lockForUpdate()->find($tarea->posicion_destino_id)
                : Posicion::query()
                    ->where('camara_id', $contexto['camara_retorno_id'] ?? null)
                    ->where('banda', (int) ($contexto['banda_retorno'] ?? 0))
                    ->where('nivel', (int) ($contexto['nivel_retorno'] ?? 0))
                    ->where('posicion', (int) ($contexto['profundidad_resultante'] ?? 0))
                    ->lockForUpdate()
                    ->first();
            if (! $destino || $destino->estado !== EstadoPosicion::Activa) {
                throw new ConflictoOperacion(
                    'La posición calculada para devolver un pallet ya no está habilitada.',
                );
            }
            if (UbicacionActual::query()
                ->where('posicion_id', $destino->id)
                ->lockForUpdate()
                ->exists()) {
                throw new ConflictoOperacion(
                    'Una posición de retorno se encuentra ocupada; supervisión debe corregir la geometría antes de mover.',
                );
            }

            return [
                'custodia' => $custodia,
                'tarea_original' => $tarea,
                'destino' => $destino,
            ];
        })->sortBy(fn (array $retorno): int => $retorno['tarea_original']->secuencia_maniobra)
            ->values();
        if ($retornos->pluck('destino.id')->unique()->count() !== $retornos->count()) {
            throw new ConflictoOperacion(
                'Dos pallets de la custodia apuntan a la misma posición de retorno.',
            );
        }

        $plan = PlanOperacional::query()->lockForUpdate()->findOrFail(
            $maniobra->plan_operacional_id,
        );
        $ahora = now();
        $nueva = ManiobraOperacional::create([
            'plan_operacional_id' => $plan->id,
            'creado_por_user_id' => $supervisor->id,
            'estado' => EstadoManiobraOperacional::EnEjecucion,
            'prioridad' => PrioridadOperacional::Critica,
            'candidate_key' => "retorno-seguro:{$maniobra->id}:{$discrepancia->id}",
            'titulo' => Str::limit("Retorno seguro · {$maniobra->titulo}", 180, ''),
            'motivo' => 'Recuperación supervisada de pallets bajo custodia temporal.',
            'secuencia_actual' => 1,
            'costo_movimientos' => $retornos->count(),
            'beneficio_estimado' => 0,
            'riesgo_operacional' => 0,
            'contexto' => [
                'tipo_recuperacion' => AccionResolucionDiscrepancia::RetornoSeguro->value,
                'maniobra_origen_id' => $maniobra->id,
                'discrepancia_id' => $discrepancia->id,
                'supervisado_por_user_id' => $supervisor->id,
                'custodias_ids' => $custodias->pluck('id')->all(),
            ],
            'responsable_user_id' => $usuario->id,
            'dispositivo_id' => $dispositivo->id,
            'asumida_at' => $ahora,
            'iniciada_at' => $ahora,
        ]);

        $objetivos = $maniobra->objetivos()->get();
        if ($objetivos->isEmpty()) {
            $nueva->objetivos()->attach($plan->id, [
                'es_principal' => true,
                'beneficio_estimado' => 0,
                'contexto' => json_encode([
                    'tipo_objetivo' => $plan->tipo->value,
                    'recuperacion' => true,
                ], JSON_THROW_ON_ERROR),
            ]);
        } else {
            foreach ($objetivos as $objetivo) {
                $nueva->objetivos()->attach($objetivo->id, [
                    'es_principal' => $objetivo->pivot->es_principal,
                    'beneficio_estimado' => $objetivo->pivot->beneficio_estimado,
                    'contexto' => $objetivo->pivot->contexto,
                ]);
            }
        }

        $secuenciaPlan = (int) TareaMovimiento::query()
            ->where('plan_operacional_id', $plan->id)
            ->lockForUpdate()
            ->max('secuencia');
        $tareasRecuperacion = collect();
        foreach ($retornos as $indice => $retorno) {
            /** @var CustodiaTemporalManiobra $custodia */
            $custodia = $retorno['custodia'];
            /** @var TareaMovimiento $original */
            $original = $retorno['tarea_original'];
            /** @var Posicion $destino */
            $destino = $retorno['destino'];
            $tareasRecuperacion->push(TareaMovimiento::create([
                'plan_operacional_id' => $plan->id,
                'maniobra_operacional_id' => $nueva->id,
                'secuencia' => ++$secuenciaPlan,
                'secuencia_maniobra' => $indice + 1,
                'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                'tipo_paso_maniobra' => TipoPasoManiobra::RetornoBanda,
                'estado' => $indice === 0
                    ? EstadoTareaMovimiento::Pendiente
                    : EstadoTareaMovimiento::Bloqueada,
                'prioridad' => PrioridadOperacional::Critica,
                'folio_id' => $custodia->folio_id,
                'camara_origen_id' => null,
                'posicion_origen_id' => null,
                'camara_destino_id' => $destino->camara_id,
                'posicion_destino_id' => $destino->id,
                'instruccion' => 'Devuelve el pallet bajo custodia a la posición segura indicada; no cambies de tarea.',
                'contexto' => [
                    ...($original->contexto ?? []),
                    'tipo_decision' => 'retorno_seguro_supervisado',
                    'destino_precalculado_inmutable' => true,
                    'maniobra_origen_id' => $maniobra->id,
                    'tarea_reemplazada_id' => $original->id,
                    'custodia_id' => $custodia->id,
                ],
            ]));
        }

        $pasosPendientes = $maniobra->pasos()
            ->whereIn('estado', [
                EstadoTareaMovimiento::Bloqueada->value,
                EstadoTareaMovimiento::Pendiente->value,
                EstadoTareaMovimiento::Asumida->value,
            ])
            ->lockForUpdate()
            ->get();
        foreach ($pasosPendientes as $paso) {
            if (! $this->reservas->liberarParaReplanificacion(
                $paso,
                'Sufijo sustituido por retorno seguro supervisado.',
            )) {
                throw new ConflictoOperacion(
                    'Un paso de la maniobra cruzó el punto de no retorno durante la recuperación.',
                );
            }
            $reemplazo = $tareasRecuperacion->firstWhere('folio_id', $paso->folio_id)
                ?? $tareasRecuperacion->first();
            $paso->update([
                'estado' => EstadoTareaMovimiento::Cancelada,
                'cancelada_at' => $ahora,
                'reemplazada_por_tarea_id' => $reemplazo?->id,
                'cancelada_por_user_id' => $supervisor->id,
                'motivo_cancelacion' => 'Sufijo sustituido por retorno seguro supervisado.',
                'version' => $paso->version + 1,
            ]);
        }

        $maniobra->reservasBandas()
            ->whereNull('liberada_at')
            ->update(['maniobra_operacional_id' => $nueva->id]);
        foreach ($retornos as $retorno) {
            /** @var Posicion $destino */
            $destino = $retorno['destino'];
            ReservaBandaManiobra::query()->firstOrCreate([
                'maniobra_operacional_id' => $nueva->id,
                'camara_id' => $destino->camara_id,
                'banda' => $destino->banda,
                'nivel' => $destino->nivel,
            ], [
                'reservada_at' => $ahora,
            ]);
        }
        $this->bloquearBandas($nueva->load('reservasBandas'));

        foreach ($custodias as $custodia) {
            $custodia->update([
                'maniobra_operacional_id' => $nueva->id,
                'contexto' => [
                    ...($custodia->contexto ?? []),
                    'maniobra_origen_id' => $maniobra->id,
                    'retorno_seguro_ordenado_at' => $ahora->toAtomString(),
                    'retorno_seguro_supervisado_por_user_id' => $supervisor->id,
                ],
            ]);
        }

        $maniobra->update([
            'estado' => EstadoManiobraOperacional::Cancelada,
            'cancelada_at' => $ahora,
            'pausada_at' => null,
            'motivo_cancelacion' => 'Sufijo sustituido por retorno seguro supervisado.',
            'contexto' => [
                ...($maniobra->contexto ?? []),
                'maniobra_recuperacion_id' => $nueva->id,
                'accion_recuperacion' => AccionResolucionDiscrepancia::RetornoSeguro->value,
            ],
            'version' => $maniobra->version + 1,
        ]);
        $plan->update(['version' => $plan->version + 1]);

        /** @var TareaMovimiento $primera */
        $primera = $tareasRecuperacion->first();
        $this->reservas->asumir($primera->refresh(), $usuario, $dispositivo);
        $this->materializarDestinoPrecalculado(
            $primera->refresh(),
            $usuario,
            $dispositivo,
        );
    }

    private function cancelarTrasDiscrepancia(
        ManiobraOperacional $maniobra,
        User $supervisor,
    ): void {
        if (! $this->cancelarReversible(
            $maniobra,
            $supervisor,
            'Discrepancia resuelta por supervisión: se invalida el sufijo reversible.',
        )) {
            throw new ConflictoOperacion(
                'La maniobra ya modificó la realidad física; debe reanudarse para cerrar el movimiento o la custodia.',
            );
        }
    }

    public function cancelarReversible(
        ManiobraOperacional $maniobra,
        User $usuario,
        string $motivo,
    ): bool {
        return DB::transaction(function () use ($maniobra, $usuario, $motivo): bool {
            $maniobra = ManiobraOperacional::query()->lockForUpdate()->findOrFail($maniobra->id);
            if ($maniobra->estado->esFinal()) {
                return true;
            }
            if ($maniobra->pasos()
                ->where('estado', EstadoTareaMovimiento::EnProceso->value)
                ->lockForUpdate()
                ->exists()
                || $maniobra->custodiasTemporales()
                    ->where('estado', EstadoCustodiaTemporal::Activa->value)
                    ->lockForUpdate()
                    ->exists()) {
                return false;
            }

            $pasos = $maniobra->pasos()
                ->whereIn('estado', [
                    EstadoTareaMovimiento::Bloqueada->value,
                    EstadoTareaMovimiento::Pendiente->value,
                    EstadoTareaMovimiento::Asumida->value,
                ])
                ->lockForUpdate()
                ->get();
            foreach ($pasos as $paso) {
                $this->reservas->liberarParaReplanificacion($paso, $motivo);
                $paso->update([
                    'estado' => EstadoTareaMovimiento::Cancelada,
                    'cancelada_at' => now(),
                    'cancelada_por_user_id' => $usuario->id,
                    'motivo_cancelacion' => Str::limit(trim($motivo), 255, ''),
                    'version' => $paso->version + 1,
                ]);
            }
            $this->liberarBandas($maniobra, $motivo);
            $maniobra->update([
                'estado' => EstadoManiobraOperacional::Cancelada,
                'cancelada_at' => now(),
                'motivo_cancelacion' => Str::limit(trim($motivo), 255, ''),
                'version' => $maniobra->version + 1,
            ]);

            return true;
        }, attempts: 3);
    }

    public function validarPasoActual(TareaMovimiento $tarea): void
    {
        if (! $tarea->maniobra_operacional_id) {
            return;
        }
        $maniobra = ManiobraOperacional::query()->findOrFail($tarea->maniobra_operacional_id);
        $this->validarPasoActualInterno($maniobra, $tarea);
        $this->arbitraje->validarMaterializable($maniobra);
    }

    /** @param array<int, array<string, mixed>> $pasos */
    private function validarCierre(array $pasos): void
    {
        if ($pasos === []) {
            throw new DomainException('La maniobra requiere al menos un paso físico.');
        }

        $temporales = [];
        foreach ($pasos as $indice => $paso) {
            $folioId = $paso['folio_id'] ?? null;
            $tipoMovimiento = $paso['tipo_movimiento'] ?? null;
            $tipoPaso = $paso['tipo_paso_maniobra'] ?? null;
            if (! is_string($folioId) || ! Str::isUuid($folioId)) {
                throw new DomainException('Cada paso debe identificar un pallet válido.');
            }
            if (! $tipoMovimiento instanceof TipoMovimiento
                || ! $tipoPaso instanceof TipoPasoManiobra
                || $tipoMovimiento === TipoMovimiento::Reversion) {
                throw new DomainException(
                    'Cada paso requiere un tipo físico y un tipo de maniobra válidos.',
                );
            }
            if ($tipoPaso === TipoPasoManiobra::ExtraccionTemporal) {
                if ($tipoMovimiento !== TipoMovimiento::Retiro
                    || empty($paso['camara_origen_id'])
                    || empty($paso['posicion_origen_id'])
                    || ! empty($paso['camara_destino_id'])
                    || ! empty($paso['posicion_destino_id'])) {
                    throw new DomainException(
                        'Una extracción temporal debe retirar el pallet desde un origen físico y sin destino permanente.',
                    );
                }
                if (isset($temporales[$folioId])) {
                    throw new DomainException('Un pallet no puede quedar dos veces en custodia temporal.');
                }
                $temporales[$folioId] = [
                    'indice' => $indice,
                    'camara_id' => $paso['camara_origen_id'],
                    'banda' => $paso['contexto']['banda_retorno'] ?? null,
                    'nivel' => $paso['contexto']['nivel_retorno'] ?? null,
                ];
            }
            if ($tipoPaso === TipoPasoManiobra::RetornoBanda) {
                $temporal = $temporales[$folioId] ?? null;
                if (! is_array($temporal) || $temporal['indice'] >= $indice) {
                    throw new DomainException('Todo retorno debe resolver una extracción temporal anterior.');
                }
                if ($tipoMovimiento !== TipoMovimiento::UbicacionInicial
                    || ! empty($paso['camara_origen_id'])
                    || ! empty($paso['posicion_origen_id'])
                    || empty($paso['camara_destino_id'])
                    || $paso['camara_destino_id'] !== $temporal['camara_id']
                    || ($paso['contexto']['banda_retorno'] ?? null) !== $temporal['banda']
                    || ($paso['contexto']['nivel_retorno'] ?? null) !== $temporal['nivel']) {
                    throw new DomainException(
                        'El retorno debe resolver la custodia en la misma banda y nivel protegidos.',
                    );
                }
                if ((int) ($paso['contexto']['profundidad_resultante'] ?? 0) < 1) {
                    throw new DomainException(
                        'El retorno debe declarar una profundidad resultante válida.',
                    );
                }
                unset($temporales[$folioId]);
            }
        }

        if ($temporales !== []) {
            throw new DomainException(
                'No se puede publicar una maniobra con pallets temporales sin retorno.',
            );
        }
    }

    private function registrarCustodia(
        ManiobraOperacional $maniobra,
        TareaMovimiento $tarea,
        Movimiento $movimiento,
    ): void {
        if ($tarea->tipo_paso_maniobra === TipoPasoManiobra::ExtraccionTemporal) {
            $origen = Posicion::query()->findOrFail($movimiento->posicion_origen_id);
            CustodiaTemporalManiobra::create([
                'maniobra_operacional_id' => $maniobra->id,
                'folio_id' => $tarea->folio_id,
                'tarea_extraccion_id' => $tarea->id,
                'camara_origen_id' => $origen->camara_id,
                'posicion_origen_id' => $origen->id,
                'banda_origen' => $origen->banda,
                'posicion_origen' => $origen->posicion,
                'nivel_origen' => $origen->nivel,
                'estado' => EstadoCustodiaTemporal::Activa,
                'bloqueo_folio_id' => $tarea->folio_id,
                'user_id' => $movimiento->user_id,
                'dispositivo_id' => $movimiento->dispositivo_id,
                'extraido_at' => $movimiento->recibido_servidor_at,
                'contexto' => [
                    'movimiento_extraccion_id' => $movimiento->id,
                    'sin_ubicacion_permanente' => true,
                ],
            ]);

            return;
        }

        if ($tarea->tipo_paso_maniobra !== TipoPasoManiobra::RetornoBanda) {
            return;
        }

        $custodia = CustodiaTemporalManiobra::query()
            ->where('maniobra_operacional_id', $maniobra->id)
            ->where('bloqueo_folio_id', $tarea->folio_id)
            ->lockForUpdate()
            ->first();
        if (! $custodia) {
            throw new DomainException('El retorno no posee una custodia temporal activa.');
        }
        $custodia->update([
            'tarea_resolucion_id' => $tarea->id,
            'estado' => EstadoCustodiaTemporal::ResueltaRetorno,
            'bloqueo_folio_id' => null,
            'resuelto_at' => $movimiento->recibido_servidor_at,
            'contexto' => [
                ...($custodia->contexto ?? []),
                'movimiento_resolucion_id' => $movimiento->id,
                'posicion_resultante_id' => $movimiento->posicion_destino_id,
            ],
        ]);
    }

    private function bloquearBandas(ManiobraOperacional $maniobra): void
    {
        foreach ($maniobra->reservasBandas as $reserva) {
            if ($reserva->clave_bloqueo) {
                continue;
            }
            try {
                $reserva->update([
                    'clave_bloqueo' => implode(':', [
                        $reserva->camara_id,
                        $reserva->banda,
                        $reserva->nivel,
                    ]),
                    'reservada_at' => now(),
                    'liberada_at' => null,
                    'motivo_liberacion' => null,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                throw new ConflictoOperacion(
                    'La banda requerida está comprometida por otra maniobra.',
                    previous: $exception,
                );
            }
        }
    }

    private function liberarBandas(ManiobraOperacional $maniobra, string $motivo): void
    {
        $maniobra->reservasBandas()
            ->whereNull('liberada_at')
            ->update([
                'clave_bloqueo' => null,
                'liberada_at' => now(),
                'motivo_liberacion' => Str::limit(trim($motivo), 255, ''),
            ]);
    }

    private function materializarDestinoPrecalculado(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
    ): void {
        if (! $tarea->posicion_destino_id || $tarea->tipo_movimiento === TipoMovimiento::Retiro) {
            return;
        }
        $reserva = ReservaTareaMovimiento::query()
            ->where('bloqueo_tarea_id', $tarea->id)
            ->where('estado', EstadoReservaTareaMovimiento::Activa->value)
            ->lockForUpdate()
            ->first();
        if ($reserva?->bloqueo_posicion_id === $tarea->posicion_destino_id) {
            return;
        }
        $this->reservas->materializarDestino(
            $tarea,
            Posicion::query()->findOrFail($tarea->posicion_destino_id),
            $usuario,
            $dispositivo,
        );
    }

    private function validarPasoActualInterno(
        ManiobraOperacional $maniobra,
        TareaMovimiento $tarea,
    ): void {
        if ($maniobra->estado->esFinal()) {
            throw new ConflictoOperacion('La maniobra ya está finalizada.');
        }
        if ($tarea->maniobra_operacional_id !== $maniobra->id
            || $tarea->secuencia_maniobra !== $maniobra->secuencia_actual
            || $tarea->estado === EstadoTareaMovimiento::Bloqueada) {
            throw new ConflictoOperacion('El paso todavía no corresponde en la secuencia física.');
        }
    }

    private function cargar(ManiobraOperacional $maniobra): ManiobraOperacional
    {
        return $maniobra->load(['pasos', 'objetivos', 'reservasBandas']);
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
}
