<?php

namespace App\Services\Prefrio;

use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\PlanOperacional;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Cargas\ServicioPlanConcentracionCarga;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\InspeccionSag\ServicioPreparacionFisicaSag;
use App\Services\Planificador\ElegibilidadFolioPlanificador;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ServicioGeneracionRecepcionTunel
{
    private const REFERENCIA_TIPO = 'proceso_prefrio';

    public function __construct(
        private readonly ServicioPlanesOperacionales $planes,
        private readonly ServicioPreparacionFisicaSag $preparacionSag,
    ) {}

    public function generar(
        ProcesoPrefrio $proceso,
        User $usuario,
    ): ?PlanOperacional {
        return DB::transaction(
            fn (): ?PlanOperacional => $this->generarBloqueado($proceso, $usuario),
            attempts: 3,
        );
    }

    private function generarBloqueado(
        ProcesoPrefrio $proceso,
        User $usuario,
    ): ?PlanOperacional {
        if (! config('planificador.generacion_automatica')
            || config('planificador.mode') !== 'guided'
            || ! in_array(config('planificador.compute'), ['tablet', 'server'], true)) {
            return null;
        }

        $proceso = ProcesoPrefrio::query()
            ->with([
                'temporada',
                'tunel:id,codigo,nombre',
                'folios' => fn ($consulta) => $consulta
                    ->conAprobacion()
                    ->with([
                        'posicion:id,tunel_prefrio_id,numero,etiqueta',
                        'folio:id,temporada_id,numero_folio,tipo_bulto,activo,estado_operacional,habilitacion_almacenamiento,marca,exportadora,variedad,calibre,datos_externos',
                        'folio.ubicacionActual:id,folio_id,camara_id,posicion_id',
                        'folio.asignacionCargaActual.carga.presenciaAndenActiva',
                    ]),
            ])
            ->lockForUpdate()
            ->findOrFail($proceso->id);

        if ($proceso->folios->isEmpty()) {
            return null;
        }

        $this->preparacionSag->sincronizarDesdePrefrio($proceso, $usuario);
        $existente = $this->planExistente($proceso->id);
        $asignaciones = $proceso->folios
            ->filter(fn (ProcesoPrefrioFolio $asignacion): bool => $this->requiereAlmacenamiento($asignacion))
            ->values();

        if ($asignaciones->isEmpty()) {
            return $existente;
        }

        $tunel = $proceso->tunel;
        $tareas = $asignaciones
            ->map(function (ProcesoPrefrioFolio $asignacion) use ($proceso, $tunel): array {
                $folio = $asignacion->folio;
                $enTunel = ! $asignacion->retiroParcialConfirmado();
                $origen = $enTunel ? ($tunel?->nombre ?? $proceso->codigo) : 'Punto de entrega de Prefrío';

                return [
                    'folio_id' => $folio->id,
                    'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                    'prioridad' => PrioridadOperacional::Alta,
                    'instruccion' => sprintf(
                        'Retirar %s de %s y ubicarlo según la frontera operacional vigente.',
                        $folio->numero_folio,
                        $origen,
                    ),
                    'contexto' => array_filter([
                        'proceso_prefrio_id' => $proceso->id,
                        'tunel_prefrio_id' => $proceso->tunel_prefrio_id,
                        'tunel_codigo' => $tunel?->codigo,
                        'tunel_nombre' => $origen,
                        'posicion_tunel_prefrio_id' => $enTunel ? $asignacion->posicion_tunel_prefrio_id : null,
                        'posicion_tunel' => $enTunel ? $asignacion->posicion?->etiqueta : null,
                        'marca' => $folio->marca,
                        'formato' => $folio->datos_externos['envase'] ?? null,
                        'formato_prefrio' => $proceso->formato_referencia,
                        'cliente' => $folio->exportadora,
                        'exportadora' => $folio->exportadora,
                        'variedad' => $folio->variedad,
                        'calibre' => $folio->calibre,
                    ], static fn (mixed $valor): bool => $valor !== null && $valor !== ''),
                ];
            })
            ->all();

        if ($existente && $existente->estado !== EstadoPlanOperacional::Cancelado) {
            if ($existente->estado === EstadoPlanOperacional::Completado) {
                $existente->update([
                    'estado' => EstadoPlanOperacional::Programado,
                    'completado_at' => null,
                    'completado_por_user_id' => null,
                    'version' => $existente->version + 1,
                ]);
            }
            foreach ($tareas as $datos) {
                $nueva = $this->planes->agregarTareaRolling($existente, $datos);
                $this->vincularRestauracionSag($nueva);
            }
            $existente->refresh()->update([
                'contexto' => [
                    ...($existente->contexto ?? []),
                    'total_folios_aprobados' => $proceso->folios->count(),
                    'total_pallets_planificados' => $existente->tareas()->distinct()->count('folio_id'),
                ],
                'version' => $existente->version + 1,
            ]);
            $this->coordinarCargas($existente, $usuario);

            return $existente->refresh();
        }

        try {
            $plan = $this->planes->crear(
                temporada: $proceso->temporada,
                tipo: TipoPlanOperacional::RecepcionTunel,
                titulo: sprintf(
                    'Recibir %s · %s',
                    $tunel?->nombre ?? 'túnel',
                    $proceso->codigo,
                ),
                creadoPor: $usuario,
                tareas: $tareas,
                prioridad: PrioridadOperacional::Alta,
                motivo: 'Prefrío aprobado para los pallets incluidos en esta recepción.',
                referenciaTipo: self::REFERENCIA_TIPO,
                referenciaId: $proceso->id,
                contexto: [
                    'planner_horizon' => 'rolling',
                    'origen_logico' => 'tunel_prefrio',
                    'proceso_prefrio_id' => $proceso->id,
                    'tunel_prefrio_id' => $proceso->tunel_prefrio_id,
                    'tunel_codigo' => $tunel?->codigo,
                    'tunel_nombre' => $tunel?->nombre,
                    'condicion_termino' => 'todos_pallets_recepcion_completados',
                    'total_folios_aprobados' => $proceso->folios->count(),
                    'total_pallets_planificados' => count($tareas),
                ],
                cicloReferencia: $existente ? $existente->ciclo_referencia + 1 : null,
            );
            foreach ($plan->tareas()->get() as $nueva) {
                $this->vincularRestauracionSag($nueva);
            }
            $this->coordinarCargas($plan, $usuario);

            return $plan;
        } catch (UniqueConstraintViolationException) {
            return $this->planExistente($proceso->id)
                ?? throw new DomainException(
                    'No fue posible recuperar el objetivo de recepción creado concurrentemente.',
                );
        }
    }

    private function requiereAlmacenamiento(ProcesoPrefrioFolio $asignacion): bool
    {
        $folio = $asignacion->folio;
        if (! $folio
            || ! ElegibilidadFolioPlanificador::operable($folio)
            || $folio->tipo_bulto !== TipoBulto::Pallet
            || ($asignacion->finalizado_parcial_at !== null
                && $folio->habilitacion_almacenamiento !== HabilitacionAlmacenamientoFolio::Habilitado)
            || $folio->ubicacionActual !== null) {
            return false;
        }

        if (TareaMovimiento::query()
            ->where('folio_id', $folio->id)
            ->whereNotIn('estado', [
                EstadoTareaMovimiento::Completada->value,
                EstadoTareaMovimiento::Cancelada->value,
            ])
            ->lockForUpdate()
            ->exists()
            || TareaMovimiento::query()
                ->where('folio_id', $folio->id)
                ->whereIn('estado', [EstadoTareaMovimiento::Completada, EstadoTareaMovimiento::Cancelada])
                ->whereHas('planOperacional', fn ($plan) => $plan
                    ->where('referencia_tipo', self::REFERENCIA_TIPO)
                    ->where('referencia_id', $asignacion->proceso_prefrio_id)
                    ->where(fn ($final) => $final->where('estado', EstadoPlanOperacional::Cancelado)
                        ->orWhere('tareas_movimiento.estado', EstadoTareaMovimiento::Completada)))
                ->exists()
            || ($asignacion->finalizado_parcial_at !== null && CargaFolio::query()->where('folio_id', $folio->id)
                ->whereIn('estado', [EstadoCargaFolio::EnAnden, EstadoCargaFolio::Cargado])->exists())) {
            return false;
        }

        $carga = $folio->asignacionCargaActual?->carga;

        return $carga?->presenciaAndenActiva === null;
    }

    private function vincularRestauracionSag(TareaMovimiento $nueva): void
    {
        TareaMovimiento::query()
            ->where('folio_id', $nueva->folio_id)
            ->where('estado', EstadoTareaMovimiento::Cancelada->value)
            ->whereNull('reemplazada_por_tarea_id')
            ->whereHas('planOperacional', fn ($consulta) => $consulta
                ->where('referencia_tipo', ServicioPreparacionFisicaSag::REFERENCIA_PLAN))
            ->update(['reemplazada_por_tarea_id' => $nueva->id]);
    }

    private function coordinarCargas(PlanOperacional $plan, User $usuario): void
    {
        $cargas = Carga::query()
            ->whereHas('asignacionesActuales', fn ($consulta) => $consulta
                ->whereIn('folio_id', $plan->tareas()->select('folio_id')))
            ->get();
        foreach ($cargas as $carga) {
            app(ServicioPlanConcentracionCarga::class)->sincronizar($carga, $usuario);
        }
    }

    private function planExistente(string $procesoId): ?PlanOperacional
    {
        return PlanOperacional::query()
            ->where('referencia_tipo', self::REFERENCIA_TIPO)
            ->where('referencia_id', $procesoId)
            ->orderByDesc('ciclo_referencia')
            ->lockForUpdate()
            ->first();
    }
}
