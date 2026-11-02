<?php

namespace App\Services\Cargas;

use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoReingresoCamara;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\OrigenReingresoCamara;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\Movimiento;
use App\Models\PlanOperacional;
use App\Models\ReingresoFolioCamara;
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pallets que salieron de la cámara por una carga y deben volver: el folio
 * conserva su número y queda bloqueado para otras cargas hasta ubicarse.
 */
class ServicioReingresoCamara
{
    private const TAREAS_ACTIVAS = ['bloqueada', 'pendiente', 'asumida', 'en_proceso'];

    /** Se llama dentro de la transacción que ya bloqueó la carga y la asignación. */
    public function registrar(
        Folio $folio,
        ?Carga $carga,
        ?CargaFolio $asignacion,
        OrigenReingresoCamara $origen,
        string $motivo,
        User $usuario,
        ?EstadoCargaFolio $estadoPrevio = null,
    ): ReingresoFolioCamara {
        try {
            $reingreso = ReingresoFolioCamara::create([
                'temporada_id' => $carga?->temporada_id ?? $folio->temporada_id,
                'folio_id' => $folio->id,
                'folio_pendiente_id' => $folio->id,
                'carga_id' => $carga?->id,
                'carga_folio_id' => $asignacion?->id,
                'origen' => $origen,
                'estado' => EstadoReingresoCamara::Pendiente,
                'estado_carga_previo' => $estadoPrevio?->value,
                'anden_id' => $asignacion?->anden_id,
                'motivo' => $motivo,
                'registrado_por_user_id' => $usuario->id,
                'registrado_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000') {
                throw new ConflictoOperacion(
                    "El folio {$folio->numero_folio} ya tiene un reingreso a cámara pendiente.",
                    previous: $exception,
                );
            }

            throw $exception;
        }

        // La tarea se publica fuera de la transacción de la carga: el plan bloquea
        // la temporada y no debe encadenarse detrás de los bloqueos de la carga.
        DB::afterCommit(function () use ($reingreso): void {
            try {
                $this->programarUbicacion($reingreso->id);
            } catch (Throwable $error) {
                report($error);
            }
        });

        return $reingreso;
    }

    /**
     * Publica la tarea de ubicación para que el planificador asigne destino
     * (horizonte rolling, prioridad alta). Si el planificador está apagado, el
     * pallet queda en la bandeja y el camarero lo ubica manualmente.
     */
    public function programarUbicacion(string $reingresoId, ?User $usuario = null): ?PlanOperacional
    {
        try {
            return $this->crearPlanUbicacion($reingresoId, $usuario);
        } catch (Throwable $error) {
            // El retorno ya está confirmado. Conservar un motivo legible para poder
            // recuperar su trabajo sin volver a registrar el retorno comercial.
            ReingresoFolioCamara::query()->whereKey($reingresoId)
                ->where('estado', EstadoReingresoCamara::Pendiente->value)
                ->update([
                    'programacion_intentada_at' => now(),
                    'programacion_error' => $error instanceof DomainException || $error instanceof ConflictoOperacion
                        ? mb_substr($error->getMessage(), 0, 500)
                        : 'No se pudo preparar la labor. Reintenta desde esta bandeja; si persiste, solicita revisión a supervisión.',
                ]);

            throw $error;
        }
    }

    private function crearPlanUbicacion(string $reingresoId, ?User $usuario): ?PlanOperacional
    {
        if (! config('planificador.generacion_automatica') || config('planificador.mode') === 'off') {
            return null;
        }

        return DB::transaction(function () use ($reingresoId, $usuario): ?PlanOperacional {
            $leido = ReingresoFolioCamara::query()->find($reingresoId);
            if (! $leido) {
                return null;
            }
            $folio = Folio::query()->lockForUpdate()->find($leido->folio_id);
            $reingreso = ReingresoFolioCamara::query()->with(['carga:id,codigo', 'anden:id,codigo', 'registradoPor:id,activo'])
                ->lockForUpdate()->find($reingresoId);
            if (! $folio || ! $reingreso || $reingreso->estado !== EstadoReingresoCamara::Pendiente) {
                return null;
            }
            $temporada = app(ServicioTemporadaActiva::class)->buscar();
            if (! $temporada || $temporada->id !== $reingreso->temporada_id) {
                throw new DomainException('El reingreso no pertenece a la temporada activa.');
            }
            $creador = $usuario ?? $reingreso->registradoPor;
            if (! $creador?->activo) {
                throw new DomainException('El usuario que registró el retorno está inactivo. Supervisión debe reintentar la programación.');
            }
            if (! $folio->activo) {
                throw new DomainException('El folio ya no está activo. Revisa su estado antes de programar el reingreso.');
            }
            if ($folio->ubicacionActual()->exists()) {
                throw new DomainException('El folio ya figura ubicado en cámara. Revisa la ubicación física antes de programar otra labor.');
            }
            // Una tarea vigente del mismo folio ya lo guía: no duplicar trabajo.
            $vigente = TareaMovimiento::query()
                ->where('folio_id', $folio->id)
                ->whereIn('estado', self::TAREAS_ACTIVAS)
                ->with('planOperacional')->lockForUpdate()->first();
            if ($vigente) {
                if ($vigente->planOperacional->referencia_tipo === 'reingreso_camara'
                    && $vigente->planOperacional->referencia_id === $reingreso->id) {
                    $reingreso->update(['plan_operacional_id' => $vigente->plan_operacional_id, 'programacion_error' => null]);

                    return $vigente->planOperacional;
                }
                throw new ConflictoOperacion('El folio tiene otra labor activa. Debe resolverse antes de programar su reingreso.');
            }

            $ciclo = 1 + (int) PlanOperacional::query()->where('referencia_tipo', 'reingreso_camara')
                ->where('referencia_id', $reingreso->id)->max('ciclo_referencia');
            $origen = trim(($reingreso->anden?->codigo ? "andén {$reingreso->anden->codigo}" : 'andén').($reingreso->carga ? " · {$reingreso->carga->codigo}" : ''));
            $plan = app(ServicioPlanesOperacionales::class)->crear(
                temporada: $temporada,
                tipo: TipoPlanOperacional::AlmacenamientoPallet,
                titulo: "Reingreso a cámara · {$folio->numero_folio}",
                creadoPor: $creador,
                tareas: [[
                    'folio_id' => $folio->id,
                    'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                    'prioridad' => PrioridadOperacional::Alta,
                    'instruccion' => "Retirar {$folio->numero_folio} desde {$origen} y ubicarlo en cámara. Si corresponde, registrar la temperatura de pulpa.",
                    'contexto' => array_filter([
                        'origen_logico' => 'reingreso_despacho',
                        'reingreso_camara_id' => $reingreso->id,
                        'origen_reingreso' => $reingreso->origen->value,
                        'carga_codigo' => $reingreso->carga?->codigo,
                        'anden' => $reingreso->anden?->codigo,
                        'confirmar_folio_fisicamente' => true,
                        'pedir_temperatura_pulpa' => true,
                        'cliente' => $folio->exportadora,
                        'exportadora' => $folio->exportadora,
                        'marca' => $folio->marca,
                        'formato' => data_get($folio->datos_externos, 'envase'),
                        'variedad' => $folio->variedad,
                        'calibre' => $folio->calibre,
                    ], static fn (mixed $valor): bool => $valor !== null && $valor !== ''),
                ]],
                prioridad: PrioridadOperacional::Alta,
                motivo: $reingreso->origen->etiqueta().': '.$reingreso->motivo,
                referenciaTipo: 'reingreso_camara',
                referenciaId: $reingreso->id,
                contexto: ['planner_horizon' => 'rolling', 'origen_logico' => 'reingreso_despacho'],
                cicloReferencia: $ciclo,
            );
            $reingreso->update(['plan_operacional_id' => $plan->id, 'programacion_error' => null, 'programacion_intentada_at' => now()]);

            return $plan;
        }, attempts: 3);
    }

    /** Recupera trabajo pendiente, nunca vuelve a registrar la devolución de la carga. */
    public function recuperarPendientes(int $limite = 100): array
    {
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        if (! $temporada || ! config('planificador.generacion_automatica') || config('planificador.mode') === 'off') {
            return ['programados' => 0, 'bloqueados' => 0];
        }
        $pendientes = ReingresoFolioCamara::query()->where('temporada_id', $temporada->id)
            ->where('estado', EstadoReingresoCamara::Pendiente->value)
            ->whereDoesntHave('folio.tareasMovimiento', fn ($q) => $q->whereIn('estado', self::TAREAS_ACTIVAS))
            ->where(fn ($q) => $q->whereNull('programacion_intentada_at')->orWhere('programacion_intentada_at', '<=', now()->subMinutes(5)))
            ->orderBy('registrado_at')->limit(max(1, min(500, $limite)))->get();
        $resultado = ['programados' => 0, 'bloqueados' => 0];
        foreach ($pendientes as $pendiente) {
            try {
                if ($this->programarUbicacion($pendiente->id)) {
                    $resultado['programados']++;
                }
            } catch (Throwable $error) {
                $resultado['bloqueados']++;
                if (! $error instanceof DomainException && ! $error instanceof ConflictoOperacion) {
                    report($error);
                }
            }
        }

        return $resultado;
    }

    /** Registro interno de temperatura de pulpa: opcional, no cambia el folio ni bloquea. */
    public function registrarTemperatura(ReingresoFolioCamara $reingreso, float $temperatura, User $usuario): ReingresoFolioCamara
    {
        if ($temperatura < -5 || $temperatura > 40) {
            throw new DomainException('La temperatura de pulpa debe estar entre -5 y 40 °C.');
        }

        return DB::transaction(function () use ($reingreso, $temperatura, $usuario): ReingresoFolioCamara {
            $bloqueado = ReingresoFolioCamara::query()->lockForUpdate()->findOrFail($reingreso->id);
            $bloqueado->update([
                'temperatura_pulpa' => round($temperatura, 1),
                'temperatura_por_user_id' => $usuario->id,
                'temperatura_registrada_at' => now(),
            ]);

            return $bloqueado->refresh();
        }, attempts: 3);
    }

    /**
     * Bandeja de reingresos: lo pendiente primero (más antiguo arriba) y lo
     * ubicado en las últimas 24 horas para cerrar el ciclo a la vista.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bandeja(string $temporadaId, bool $incluirUbicados = false, int $limite = 100): array
    {
        $ahora = now();

        return ReingresoFolioCamara::query()
            ->where('temporada_id', $temporadaId)
            ->where(fn ($consulta) => $consulta
                ->where('estado', EstadoReingresoCamara::Pendiente->value)
                ->when($incluirUbicados, fn ($ubicados) => $ubicados->orWhere('ubicado_at', '>=', $ahora->copy()->subDay())))
            ->with([
                'folio:id,numero_folio,exportadora,variedad,calibre,datos_externos,activo',
                'carga:id,codigo,numero_orden_externa,estado',
                'anden:id,codigo,nombre',
                'registradoPor:id,name',
                'ubicadoPor:id,name',
                'temperaturaRegistradaPor:id,name',
                'camaraUbicacion:id,codigo,nombre',
                'plan.tareas.responsable:id,name',
                'folio.ubicacionActual',
                'folio.tareasMovimiento' => fn ($q) => $q->whereIn('estado', self::TAREAS_ACTIVAS)->with('planOperacional'),
            ])
            ->orderByRaw("CASE estado WHEN 'pendiente' THEN 0 ELSE 1 END")
            ->orderBy('registrado_at')
            ->limit($limite)
            ->get()
            ->map(function (ReingresoFolioCamara $reingreso) use ($ahora): array {
                $tarea = $reingreso->plan?->tareas->sortByDesc('created_at')->first();

                return [
                    'id' => $reingreso->id,
                    'estado' => $reingreso->estado->value,
                    'folio' => [
                        'id' => $reingreso->folio_id,
                        'numero_folio' => $reingreso->folio?->numero_folio,
                        'cliente' => $reingreso->folio?->exportadora,
                        'variedad' => $reingreso->folio?->variedad,
                        'calibre' => $reingreso->folio?->calibre,
                        'embalaje' => data_get($reingreso->folio?->datos_externos, 'envase'),
                    ],
                    'origen' => $reingreso->origen->value,
                    'origen_etiqueta' => $reingreso->origen->etiqueta(),
                    'estado_carga_previo' => $reingreso->estado_carga_previo,
                    'motivo' => $reingreso->motivo,
                    'carga' => $reingreso->carga ? [
                        'id' => $reingreso->carga->id,
                        'codigo' => $reingreso->carga->codigo,
                        'numero_orden_externa' => $reingreso->carga->numero_orden_externa,
                    ] : null,
                    'anden' => $reingreso->anden?->codigo,
                    'registrado_por' => $reingreso->registradoPor?->name,
                    'registrado_at' => $reingreso->registrado_at?->toAtomString(),
                    'antiguedad_minutos' => $reingreso->registrado_at
                        ? (int) floor($reingreso->registrado_at->diffInMinutes($reingreso->ubicado_at ?? $ahora))
                        : null,
                    'temperatura_pulpa' => $reingreso->temperatura_pulpa === null ? null : (float) $reingreso->temperatura_pulpa,
                    'temperatura_registrada_por' => $reingreso->temperaturaRegistradaPor?->name,
                    'temperatura_registrada_at' => $reingreso->temperatura_registrada_at?->toAtomString(),
                    'tarea' => $tarea ? [
                        'id' => $tarea->id,
                        'estado' => $tarea->estado->value,
                        'responsable' => $tarea->responsable?->name,
                    ] : null,
                    'programacion' => $this->estadoProgramacion($reingreso, $tarea),
                    'ubicado_en' => $reingreso->camaraUbicacion?->codigo,
                    'ubicado_por' => $reingreso->ubicadoPor?->name,
                    'ubicado_at' => $reingreso->ubicado_at?->toAtomString(),
                ];
            })
            ->values()
            ->all();
    }

    private function estadoProgramacion(ReingresoFolioCamara $reingreso, ?TareaMovimiento $tarea): array
    {
        if ($reingreso->estado === EstadoReingresoCamara::Ubicado) {
            return ['estado' => 'ubicado', 'motivo' => null, 'puede_programar' => false];
        }
        if ($tarea && ! $tarea->estado->esFinal()) {
            return ['estado' => 'programada', 'motivo' => null, 'puede_programar' => false];
        }
        if ($reingreso->folio?->ubicacionActual) {
            return ['estado' => 'bloqueada', 'motivo' => 'El folio ya figura ubicado en cámara. Revisa su ubicación física.', 'puede_programar' => false];
        }
        if ($reingreso->folio?->tareasMovimiento->isNotEmpty()) {
            return ['estado' => 'bloqueada', 'motivo' => 'El folio tiene otra labor activa. Resuélvela antes de programar el reingreso.', 'puede_programar' => false];
        }
        if (! config('planificador.generacion_automatica') || config('planificador.mode') === 'off') {
            return ['estado' => 'sin_planificador', 'motivo' => 'El planificador está desactivado. La ubicación manual sigue disponible.', 'puede_programar' => false];
        }

        return [
            'estado' => $reingreso->programacion_error ? 'bloqueada' : 'pendiente',
            'motivo' => $reingreso->programacion_error ?? ($tarea ? 'La labor anterior terminó sin cerrar el reingreso. Revisa y vuelve a programar.' : 'El pallet está pendiente de una labor de reingreso.'),
            'puede_programar' => (bool) $reingreso->folio?->activo,
        ];
    }

    public function pendiente(string $folioId, bool $bloquear = false): ?ReingresoFolioCamara
    {
        return ReingresoFolioCamara::query()
            ->where('folio_pendiente_id', $folioId)
            ->when($bloquear, fn ($consulta) => $consulta->lockForUpdate())
            ->first();
    }

    /** El pallet volvió a una posición de cámara: el reingreso se cierra con ese movimiento. */
    public function cerrarPorUbicacion(Folio $folio, Movimiento $movimiento, User $usuario): ?ReingresoFolioCamara
    {
        $reingreso = $this->pendiente($folio->id, bloquear: true);
        if (! $reingreso) {
            return null;
        }

        $reingreso->update([
            'estado' => EstadoReingresoCamara::Ubicado,
            'folio_pendiente_id' => null,
            'movimiento_ubicacion_id' => $movimiento->id,
            'camara_ubicacion_id' => $movimiento->camara_destino_id,
            'ubicado_por_user_id' => $usuario->id,
            'ubicado_at' => now(),
        ]);

        // Si se ubicó a mano, la tarea guiada que nadie tomó ya no tiene objeto.
        $planId = $reingreso->plan_operacional_id;
        if ($planId) {
            DB::afterCommit(function () use ($planId, $movimiento, $usuario): void {
                try {
                    TareaMovimiento::query()
                        ->where('plan_operacional_id', $planId)
                        ->where('estado', EstadoTareaMovimiento::Pendiente->value)
                        ->when($movimiento->tarea_movimiento_id, fn ($consulta, $tareaId) => $consulta->whereKeyNot($tareaId))
                        ->get()
                        ->each(fn (TareaMovimiento $tarea) => app(ServicioPlanesOperacionales::class)
                            ->cancelarPorReplanificacion($tarea, $usuario, 'El pallet ya volvió a una posición de cámara.'));
                } catch (Throwable $error) {
                    report($error);
                }
            });
        }

        return $reingreso;
    }
}
