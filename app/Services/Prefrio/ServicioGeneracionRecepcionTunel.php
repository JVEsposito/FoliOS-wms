<?php

namespace App\Services\Prefrio;

use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Models\Carga;
use App\Models\PlanOperacional;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Cargas\ServicioPlanConcentracionCarga;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\InspeccionSag\ServicioPreparacionFisicaSag;
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
            || config('planificador.compute') !== 'tablet') {
            return null;
        }

        $proceso = ProcesoPrefrio::query()
            ->with([
                'temporada',
                'tunel:id,codigo,nombre',
                'folios' => fn ($consulta) => $consulta
                    ->where('estado', EstadoFolioProcesoPrefrio::Aprobado->value)
                    ->with([
                        'posicion:id,tunel_prefrio_id,numero,etiqueta',
                        'folio:id,temporada_id,numero_folio,tipo_bulto,activo,marca,exportadora,variedad,calibre,datos_externos',
                        'folio.ubicacionActual:id,folio_id,camara_id,posicion_id',
                        'folio.asignacionCargaActual.carga.presenciaAndenActiva',
                    ]),
            ])
            ->lockForUpdate()
            ->findOrFail($proceso->id);

        if ($proceso->estado !== EstadoProcesoPrefrio::Aprobado) {
            throw new DomainException(
                'El objetivo de recepción solo puede generarse para un proceso de prefrío aprobado.',
            );
        }

        $this->preparacionSag->sincronizarDesdePrefrio($proceso, $usuario);
        $existente = $this->planExistente($proceso->id);
        if ($existente?->estado === EstadoPlanOperacional::Cancelado) {
            return $existente;
        }

        $asignaciones = $proceso->folios
            ->filter(fn (ProcesoPrefrioFolio $asignacion): bool => $this->requiereAlmacenamiento($asignacion, $existente))
            ->values();

        if ($asignaciones->isEmpty()) {
            return $existente;
        }

        $tunel = $proceso->tunel;
        $tareas = $asignaciones
            ->map(function (ProcesoPrefrioFolio $asignacion) use ($proceso, $tunel): array {
                $folio = $asignacion->folio;

                return [
                    'folio_id' => $folio->id,
                    'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                    'prioridad' => PrioridadOperacional::Alta,
                    'instruccion' => sprintf(
                        'Retirar %s de %s y ubicarlo según la frontera operacional vigente.',
                        $folio->numero_folio,
                        $tunel?->nombre ?? $proceso->codigo,
                    ),
                    'contexto' => array_filter([
                        'proceso_prefrio_id' => $proceso->id,
                        'tunel_prefrio_id' => $proceso->tunel_prefrio_id,
                        'tunel_codigo' => $tunel?->codigo,
                        'tunel_nombre' => $tunel?->nombre,
                        'posicion_tunel_prefrio_id' => $asignacion->posicion_tunel_prefrio_id,
                        'posicion_tunel' => $asignacion->posicion?->etiqueta,
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

        if ($existente) {
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
                motivo: 'Prefrío aprobado: liberar el túnel y ubicar sus pallets completos.',
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

    private function requiereAlmacenamiento(
        ProcesoPrefrioFolio $asignacion,
        ?PlanOperacional $existente,
    ): bool {
        $folio = $asignacion->folio;
        if (! $folio
            || ! $folio->activo
            || $folio->tipo_bulto !== TipoBulto::Pallet
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
            || $existente?->tareas()
                ->where('folio_id', $folio->id)
                ->where('estado', EstadoTareaMovimiento::Completada->value)
                ->exists()) {
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
            ->lockForUpdate()
            ->first();
    }
}
