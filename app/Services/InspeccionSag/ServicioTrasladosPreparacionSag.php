<?php

namespace App\Services\InspeccionSag;

use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoCustodiaTemporal;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\TipoBulto;
use App\Enums\TipoEspacioPreparacionSag;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Enums\UsoBandaOperacional;
use App\Models\CustodiaTemporalManiobra;
use App\Models\Folio;
use App\Models\LoteInspeccionSag;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ProcesoPrefrioFolio;
use App\Models\ReservaPosicionInspeccionSag;
use App\Models\TareaMovimiento;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Planificador\ServicioDesplieguePlanificador;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioTrasladosPreparacionSag
{
    public function __construct(
        private readonly ServicioPlanesOperacionales $planes,
        private readonly ServicioDesplieguePlanificador $despliegue,
    ) {}

    public function sincronizar(
        LoteInspeccionSag $lote,
        PlanOperacional $plan,
        User $usuario,
    ): void {
        if ($plan->estado->esFinal()) {
            return;
        }

        $reservas = ReservaPosicionInspeccionSag::query()
            ->where('lote_inspeccion_sag_id', $lote->id)
            ->where('tipo_espacio', TipoEspacioPreparacionSag::Pallet->value)
            ->whereNotNull('clave_bloqueo')
            ->with('posicion.camara')
            ->orderBy('orden')
            ->lockForUpdate()
            ->get();
        if ($reservas->count() !== $lote->folios->count()) {
            return;
        }

        $folios = Folio::query()
            ->whereIn('id', $lote->folios->pluck('folio_id'))
            ->with([
                // Una lectura de snapshot puede conservar el túnel como origen
                // aunque otro movimiento ya haya ubicado el pallet en cámara.
                'ubicacionActual' => fn ($consulta) => $consulta
                    ->with(['posicion', 'camara'])
                    ->orderBy('folio_id')
                    ->lockForUpdate(),
                'asignacionCargaActual',
            ])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $posiciones = $reservas->pluck('posicion')->sortByDesc('posicion')->keyBy('id');
        $destinos = $this->asignarDestinos($folios, $posiciones, $plan);
        $preparados = 0;

        foreach ($folios as $folio) {
            $actual = $folio->ubicacionActual;
            $tarea = TareaMovimiento::query()
                ->where('folio_id', $folio->id)
                ->whereNotIn('estado', [
                    EstadoTareaMovimiento::Completada->value,
                    EstadoTareaMovimiento::Cancelada->value,
                ])
                ->with('planOperacional')
                ->lockForUpdate()
                ->first();

            $anterior = null;
            if ($actual && $tarea?->plan_operacional_id === $plan->id) {
                $anterior = $this->recuperarIngresoConUbicacionPrevia($tarea, $actual, $usuario);
                if ($anterior) {
                    $tarea = null;
                }
            }

            if ($actual && $posiciones->has($actual->posicion_id)) {
                $preparados++;
                if ($tarea?->plan_operacional_id === $plan->id) {
                    $this->planes->cancelarPorReplanificacion(
                        $tarea,
                        $usuario,
                        'El pallet ya se encuentra en el sector SAG reservado.',
                    );
                }

                continue;
            }

            if (! $folio->activo
                || $folio->tipo_bulto !== TipoBulto::Pallet
                || $folio->habilitacion_almacenamiento !== HabilitacionAlmacenamientoFolio::Habilitado
                || in_array($folio->estado_operacional, [
                    EstadoOperacionalFolio::Anulado,
                    EstadoOperacionalFolio::RetiradoDefinitivo,
                    EstadoOperacionalFolio::Despachado,
                    EstadoOperacionalFolio::Agotado,
                ], true)
                || in_array($folio->asignacionCargaActual?->estado, [
                    EstadoCargaFolio::EnAnden,
                    EstadoCargaFolio::Despachado,
                ], true)) {
                continue;
            }

            $prefrio = $actual ? null : $this->origenPrefrio($folio);
            if (! $actual && ! $prefrio) {
                continue;
            }
            if ($tarea?->plan_operacional_id === $plan->id) {
                continue;
            }

            // El pallet que está listo ocupa el espacio libre más profundo.
            // No se fija destino para pallets que todavía esperan prefrío.
            $destino = $posiciones->get($destinos[$folio->id] ?? '') ?? $posiciones->first(
                fn (Posicion $posicion): bool => in_array($posicion->id, $destinos, true) === false,
            );
            if ($destino === null || $this->despliegue->dirige(array_filter([
                $actual?->camara_id,
                $destino->camara_id,
            ])) === false) {
                continue;
            }

            // Se reemplaza solo la recepción reversible. Una labor física
            // iniciada conserva su origen, su destino y su responsable.
            if ($tarea) {
                if ($tarea->planOperacional->tipo !== TipoPlanOperacional::RecepcionTunel) {
                    continue;
                }
                $anterior = $this->planes->cancelarPorReplanificacion(
                    $tarea,
                    $usuario,
                    'El pallet debe prepararse para la inspección SAG.',
                );
                if (! $anterior) {
                    continue;
                }
            }

            $nueva = $this->planes->agregarTareaRolling($plan->refresh(), [
                'folio_id' => $folio->id,
                'tipo_movimiento' => ! $actual
                    ? TipoMovimiento::UbicacionInicial
                    : ($actual->camara_id === $destino->camara_id
                        ? TipoMovimiento::Reubicacion
                        : TipoMovimiento::TrasladoEntreCamaras),
                'camara_origen_id' => $actual?->camara_id,
                'posicion_origen_id' => $actual?->posicion_id,
                'camara_destino_id' => $destino->camara_id,
                'posicion_destino_id' => $destino->id,
                'instruccion' => sprintf(
                    'Llevar %s desde %s a %s · %s para la inspección %s.',
                    $folio->numero_folio,
                    $actual
                        ? $actual->camara->nombre.' · '.$actual->posicion->etiqueta
                        : ($prefrio->proceso->tunel?->nombre ?? 'prefrío'),
                    $destino->camara->nombre,
                    $destino->etiqueta,
                    $lote->codigo,
                ),
                'contexto' => array_filter([
                    'tipo_decision' => 'preparar_inspeccion_sag',
                    'lote_inspeccion_sag_id' => $lote->id,
                    'inspeccion_sag_codigo' => $lote->codigo,
                    'origen_logico' => $actual ? 'camara' : 'tunel_prefrio',
                    'tarea_origen_corregido_id' => isset($anterior?->contexto['origen_sag_corregido'])
                        ? $anterior->id
                        : null,
                    'proceso_prefrio_id' => $prefrio?->proceso_prefrio_id,
                    'tunel_prefrio_id' => $prefrio?->proceso->tunel_prefrio_id,
                    'tunel_codigo' => $prefrio?->proceso->tunel?->codigo,
                    'tunel_nombre' => $prefrio?->proceso->tunel?->nombre,
                    'posicion_tunel_prefrio_id' => $prefrio?->posicion_tunel_prefrio_id,
                    'posicion_tunel' => $prefrio?->posicion?->etiqueta,
                    'uso_banda_destino' => UsoBandaOperacional::Inspeccion->value,
                    'destino_precalculado_inmutable' => true,
                    'cliente' => $folio->exportadora,
                    'exportadora' => $folio->exportadora,
                    'variedad' => $folio->variedad,
                    'calibre' => $folio->calibre,
                    'marca' => $folio->marca,
                ], static fn (mixed $valor): bool => $valor !== null && $valor !== ''),
            ]);
            $destinos[$folio->id] = $destino->id;
            $anterior?->update(['reemplazada_por_tarea_id' => $nueva->id]);
        }

        $plan->refresh();
        $contexto = [
            ...($plan->contexto ?? []),
            'destinos_por_folio' => $destinos,
            'pallets_preparados' => $preparados,
            'porcentaje_preparado' => $folios->isEmpty()
                ? 0
                : (int) floor(100 * $preparados / $folios->count()),
        ];
        if ($plan->contexto !== $contexto) {
            $plan->update(['contexto' => $contexto, 'version' => $plan->version + 1]);
        }
    }

    private function recuperarIngresoConUbicacionPrevia(
        TareaMovimiento $tarea,
        UbicacionActual $actual,
        User $usuario,
    ): ?TareaMovimiento {
        if ($tarea->tipo_movimiento !== TipoMovimiento::UbicacionInicial
            || ! $actual->ubicado_at
            || $tarea->movimientos()->lockForUpdate()->exists()) {
            return null;
        }

        // La orden puede haberse creado en el túnel y quedar obsoleta antes
        // de que el camarero la inicie. No basta comparar con created_at.
        if ($actual->ubicado_at->greaterThan($tarea->created_at)) {
            if ($tarea->iniciada_at) {
                if (! $actual->ubicado_at->lessThan($tarea->iniciada_at)) {
                    return null;
                }
            } elseif ($tarea->estado === EstadoTareaMovimiento::EnProceso) {
                // Sin evidencia de cuándo inició, no se interrumpe una labor física.
                return null;
            }
        }

        return DB::transaction(function () use ($tarea, $actual, $usuario): ?TareaMovimiento {
            $maniobra = $tarea->maniobraOperacional()->lockForUpdate()->first();
            if (($maniobra && (
                $maniobra->estado->esFinal()
                || ($maniobra->contexto['contrato'] ?? null) !== 'maniobra_unitaria_v1'
                || $maniobra->pasos()->lockForUpdate()->get(['id'])->count() !== 1
                || $maniobra->custodiasTemporales()->lockForUpdate()->exists()
            )) || CustodiaTemporalManiobra::query()
                ->where('folio_id', $tarea->folio_id)
                ->where('estado', EstadoCustodiaTemporal::Activa->value)
                ->lockForUpdate()->exists()) {
                return null;
            }

            $contexto = [
                ...($tarea->contexto ?? []),
                'origen_sag_corregido' => [
                    'estado_anterior' => $tarea->estado->value,
                    'ubicacion_actual_id' => $actual->id,
                    'camara_id' => $actual->camara_id,
                    'posicion_id' => $actual->posicion_id,
                    'movimiento_id' => $actual->movimiento_id,
                    'ubicado_at' => $actual->ubicado_at->toIso8601String(),
                    'tarea_creada_at' => $tarea->created_at->toIso8601String(),
                    'tarea_iniciada_at' => $tarea->iniciada_at?->toIso8601String(),
                    'corregido_at' => now()->toIso8601String(),
                    'corregido_por_user_id' => $usuario->id,
                ],
            ];
            // La ubicación precede al inicio de esta orden de ingreso imposible:
            // iniciar la orden no creó un movimiento físico. Solo en este caso se
            // habilita la cancelación normal, dentro de la misma transacción.
            $tarea->update([
                'estado' => $tarea->estado === EstadoTareaMovimiento::EnProceso
                    ? EstadoTareaMovimiento::Asumida
                    : $tarea->estado,
                'contexto' => $contexto,
            ]);
            $cancelada = $this->planes->cancelarPorReplanificacion(
                $tarea,
                $usuario,
                'Ingreso SAG obsoleto: el pallet ya tenía una ubicación antes de iniciar el traslado.',
            );
            if (! $cancelada) {
                throw new DomainException('No fue posible recuperar la orden SAG; se conserva su estado anterior.');
            }

            return $cancelada;
        });
    }

    public function cancelarPendientes(PlanOperacional $plan, User $usuario): void
    {
        $tareas = $plan->tareas()
            ->whereNotIn('estado', [
                EstadoTareaMovimiento::Completada->value,
                EstadoTareaMovimiento::Cancelada->value,
            ])
            ->lockForUpdate()
            ->get();

        foreach ($tareas as $tarea) {
            if (! $this->planes->cancelarPorReplanificacion(
                $tarea,
                $usuario,
                'La preparación de la inspección SAG fue cerrada.',
            )) {
                throw new DomainException(
                    'No se puede cerrar la inspección SAG mientras un traslado esté iniciado o conserve un pallet en custodia.',
                );
            }
        }
    }

    /**
     * Conserva el destino de las tareas activas y los pallets ya presentes.
     * Las posiciones de separación nunca forman parte de esta asignación.
     *
     * @param  Collection<int, Folio>  $folios
     * @param  Collection<string, Posicion>  $posiciones
     * @return array<string, string>
     */
    private function asignarDestinos(
        Collection $folios,
        Collection $posiciones,
        PlanOperacional $plan,
    ): array {
        $destinos = [];
        $usadas = [];
        foreach ($folios as $folio) {
            $actual = $folio->ubicacionActual?->posicion_id;
            if ($actual && $posiciones->has($actual)) {
                $destinos[$folio->id] = $actual;
                $usadas[$actual] = true;
            }
        }

        $tareas = $plan->tareas()
            ->whereNotIn('estado', [
                EstadoTareaMovimiento::Completada->value,
                EstadoTareaMovimiento::Cancelada->value,
            ])
            ->lockForUpdate()
            ->get()
            ->keyBy('folio_id');
        foreach ($folios as $folio) {
            if (isset($destinos[$folio->id])) {
                continue;
            }
            $anterior = $tareas->get($folio->id)?->posicion_destino_id;
            if ($anterior && $posiciones->has($anterior) && ! isset($usadas[$anterior])) {
                $destinos[$folio->id] = $anterior;
                $usadas[$anterior] = true;
            }
        }

        return $destinos;
    }

    private function origenPrefrio(Folio $folio): ?ProcesoPrefrioFolio
    {
        $asignacion = ProcesoPrefrioFolio::query()
            ->where('folio_id', $folio->id)
            ->with(['proceso.tunel', 'posicion'])
            ->latest('created_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        return $asignacion?->estado === EstadoFolioProcesoPrefrio::Aprobado
            && $asignacion->proceso?->estado === EstadoProcesoPrefrio::Aprobado
            && $asignacion->proceso->temporada_id === $folio->temporada_id
            ? $asignacion
            : null;
    }
}
