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
    public function programarUbicacion(string $reingresoId): ?PlanOperacional
    {
        if (! config('planificador.generacion_automatica') || config('planificador.mode') === 'off') {
            return null;
        }

        return DB::transaction(function () use ($reingresoId): ?PlanOperacional {
            $leido = ReingresoFolioCamara::query()->find($reingresoId);
            if (! $leido) {
                return null;
            }
            $folio = Folio::query()->lockForUpdate()->find($leido->folio_id);
            $reingreso = ReingresoFolioCamara::query()->with(['carga:id,codigo', 'anden:id,codigo', 'registradoPor:id,activo'])
                ->lockForUpdate()->find($reingresoId);
            if (! $folio || ! $reingreso || $reingreso->estado !== EstadoReingresoCamara::Pendiente
                || $reingreso->plan_operacional_id !== null) {
                return null;
            }
            $temporada = app(ServicioTemporadaActiva::class)->buscar();
            if (! $temporada || $temporada->id !== $reingreso->temporada_id || ! $reingreso->registradoPor?->activo) {
                return null;
            }
            // Una tarea vigente del mismo folio ya lo guía: no duplicar trabajo.
            $vigente = TareaMovimiento::query()
                ->where('folio_id', $folio->id)
                ->whereIn('estado', [EstadoTareaMovimiento::Pendiente->value, EstadoTareaMovimiento::Asumida->value, EstadoTareaMovimiento::EnProceso->value])
                ->exists();
            if ($vigente || $folio->ubicacionActual()->exists()) {
                return null;
            }

            $origen = trim(($reingreso->anden?->codigo ? "andén {$reingreso->anden->codigo}" : 'andén').($reingreso->carga ? " · {$reingreso->carga->codigo}" : ''));
            $plan = app(ServicioPlanesOperacionales::class)->crear(
                temporada: $temporada,
                tipo: TipoPlanOperacional::AlmacenamientoPallet,
                titulo: "Reingreso a cámara · {$folio->numero_folio}",
                creadoPor: $reingreso->registradoPor,
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
            );
            $reingreso->update(['plan_operacional_id' => $plan->id]);

            return $plan;
        }, attempts: 3);
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
                'folio:id,numero_folio,exportadora,variedad,calibre,datos_externos',
                'carga:id,codigo,numero_orden_externa,estado',
                'anden:id,codigo,nombre',
                'registradoPor:id,name',
                'ubicadoPor:id,name',
                'temperaturaRegistradaPor:id,name',
                'camaraUbicacion:id,codigo,nombre',
                'plan.tareas.responsable:id,name',
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
                    'ubicado_en' => $reingreso->camaraUbicacion?->codigo,
                    'ubicado_por' => $reingreso->ubicadoPor?->name,
                    'ubicado_at' => $reingreso->ubicado_at?->toAtomString(),
                ];
            })
            ->values()
            ->all();
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
