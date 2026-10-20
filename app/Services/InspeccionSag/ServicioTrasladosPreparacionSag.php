<?php

namespace App\Services\InspeccionSag;

use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\TipoBulto;
use App\Enums\TipoEspacioPreparacionSag;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Enums\UsoBandaOperacional;
use App\Models\Folio;
use App\Models\LoteInspeccionSag;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ProcesoPrefrioFolio;
use App\Models\ReservaPosicionInspeccionSag;
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Planificador\ServicioDesplieguePlanificador;
use DomainException;
use Illuminate\Support\Collection;

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
            ->with('ubicacionActual.posicion')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $posiciones = $reservas->pluck('posicion')->keyBy('id');
        $destinos = $this->asignarDestinos($folios, $posiciones, $plan);
        $preparados = 0;

        foreach ($folios as $folio) {
            $destino = $posiciones->get($destinos[$folio->id] ?? '');
            if (! $destino) {
                continue;
            }

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

            if ($actual?->posicion_id === $destino->id) {
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
                || ! $this->despliegue->dirige(array_filter([
                    $actual?->camara_id,
                    $destino->camara_id,
                ]))) {
                continue;
            }

            $prefrio = $actual ? null : $this->origenPrefrio($folio);
            if (! $actual && ! $prefrio) {
                continue;
            }
            if ($tarea?->plan_operacional_id === $plan->id) {
                continue;
            }

            // Se reemplaza solo la recepción reversible. Una labor física
            // iniciada conserva su origen, su destino y su responsable.
            $anterior = null;
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
                    $actual ? 'su ubicación actual' : ($prefrio->proceso->tunel?->nombre ?? 'prefrío'),
                    $destino->camara->nombre,
                    $destino->etiqueta,
                    $lote->codigo,
                ),
                'contexto' => array_filter([
                    'tipo_decision' => 'preparar_inspeccion_sag',
                    'lote_inspeccion_sag_id' => $lote->id,
                    'inspeccion_sag_codigo' => $lote->codigo,
                    'origen_logico' => $actual ? 'camara' : 'tunel_prefrio',
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
     * Conserva el destino entre aprobaciones parciales y reintentos. Las
     * posiciones de separación nunca forman parte de esta asignación.
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
        $previos = $plan->contexto['destinos_por_folio'] ?? [];
        foreach ($folios as $folio) {
            if (isset($destinos[$folio->id])) {
                continue;
            }
            $anterior = $tareas->get($folio->id)?->posicion_destino_id
                ?? ($previos[$folio->id] ?? null);
            if ($anterior && $posiciones->has($anterior) && ! isset($usadas[$anterior])) {
                $destinos[$folio->id] = $anterior;
                $usadas[$anterior] = true;
            }
        }
        foreach ($folios as $folio) {
            if (isset($destinos[$folio->id])) {
                continue;
            }
            $libre = $posiciones->first(fn (Posicion $posicion): bool => ! isset($usadas[$posicion->id]));
            if ($libre) {
                $destinos[$folio->id] = $libre->id;
                $usadas[$libre->id] = true;
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
