<?php

namespace App\Services\Prefrio;

use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Models\PlanOperacional;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;

class ServicioGeneracionRecepcionTunel
{
    private const REFERENCIA_TIPO = 'proceso_prefrio';

    public function __construct(
        private readonly ServicioPlanesOperacionales $planes,
    ) {}

    public function generar(
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
                        'folio:id,temporada_id,numero_folio,tipo_bulto,activo,marca,exportadora,variedad,calibre',
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

        $existente = $this->planExistente($proceso->id);
        if ($existente) {
            return $existente;
        }

        $asignaciones = $proceso->folios
            ->filter(fn (ProcesoPrefrioFolio $asignacion): bool => $this->requiereAlmacenamiento($asignacion))
            ->values();

        if ($asignaciones->isEmpty()) {
            return null;
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
                        'formato' => $proceso->formato_referencia,
                        'cliente' => $folio->exportadora,
                        'exportadora' => $folio->exportadora,
                        'variedad' => $folio->variedad,
                        'calibre' => $folio->calibre,
                    ], static fn (mixed $valor): bool => $valor !== null && $valor !== ''),
                ];
            })
            ->all();

        try {
            return $this->planes->crear(
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
            || ! $folio->activo
            || $folio->tipo_bulto !== TipoBulto::Pallet
            || $folio->ubicacionActual !== null) {
            return false;
        }

        $carga = $folio->asignacionCargaActual?->carga;

        return $carga?->presenciaAndenActiva === null;
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
