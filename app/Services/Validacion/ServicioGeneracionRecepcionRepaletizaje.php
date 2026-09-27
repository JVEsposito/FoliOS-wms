<?php

namespace App\Services\Validacion;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\PlanOperacional;
use App\Models\Repaletizaje;
use App\Models\RepaletizajeResultado;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;

class ServicioGeneracionRecepcionRepaletizaje
{
    private const REFERENCIA_TIPO = 'repaletizaje';

    public function __construct(
        private readonly ServicioPlanesOperacionales $planes,
        private readonly ServicioPrioridadBufferRepaletizaje $prioridadBuffer,
    ) {}

    public function generar(
        Repaletizaje $repaletizaje,
        User $usuario,
    ): ?PlanOperacional {
        if (! config('planificador.generacion_automatica')
            || config('planificador.mode') !== 'guided'
            || config('planificador.compute') !== 'tablet') {
            return null;
        }

        $repaletizaje = Repaletizaje::query()
            ->with([
                'resultados:id,repaletizaje_id,folio_id,tipo_resultado',
                'resultados.folio:id,temporada_id,numero_folio,tipo_bulto,condicion_termica,activo,marca,exportadora,variedad,calibre,datos_externos',
                'resultados.folio.ubicacionActual:id,folio_id,camara_id,posicion_id',
            ])
            ->lockForUpdate()
            ->findOrFail($repaletizaje->id);

        if ($repaletizaje->estado !== 'confirmado') {
            throw new DomainException(
                'El objetivo de recepción solo puede generarse para un repaletizaje confirmado.',
            );
        }

        $existente = $this->planExistente($repaletizaje->id);
        if ($existente) {
            $this->prioridadBuffer->recalcular($existente->temporada_id);

            return $existente->refresh();
        }

        $resultados = $repaletizaje->resultados
            ->filter(fn (RepaletizajeResultado $resultado): bool => $this->requiereRetiro($resultado))
            ->values();

        if ($resultados->isEmpty()) {
            return null;
        }

        $temporada = app(ServicioTemporadaActiva::class)->buscar(bloquear: true);
        if (! $temporada || $temporada->id !== $resultados->first()->folio->temporada_id) {
            throw new DomainException(
                'Los resultados del repaletizaje no pertenecen a la temporada activa.',
            );
        }

        $tareas = $resultados
            ->map(function (RepaletizajeResultado $resultado) use ($repaletizaje): array {
                $folio = $resultado->folio;
                $ubicacion = $folio->ubicacionActual;

                return [
                    'folio_id' => $folio->id,
                    'tipo_movimiento' => $ubicacion
                        ? TipoMovimiento::TrasladoEntreCamaras
                        : TipoMovimiento::UbicacionInicial,
                    'prioridad' => PrioridadOperacional::Normal,
                    'camara_origen_id' => $ubicacion?->camara_id,
                    'posicion_origen_id' => $ubicacion?->posicion_id,
                    'instruccion' => sprintf(
                        'Retirar %s de REPA y ubicarlo según la frontera operacional vigente.',
                        $folio->numero_folio,
                    ),
                    'contexto' => array_filter([
                        'repaletizaje_id' => $repaletizaje->id,
                        'repaletizaje_codigo' => $repaletizaje->codigo,
                        'repaletizaje_resultado_id' => $resultado->id,
                        'origen_logico' => 'repaletizaje',
                        'origen_repa_sin_blockers' => true,
                        'marca' => $folio->marca,
                        'formato' => data_get($folio->datos_externos, 'envase'),
                        'cliente' => $folio->exportadora,
                        'exportadora' => $folio->exportadora,
                        'variedad' => $folio->variedad,
                        'calibre' => $folio->calibre,
                        'condicion_termica' => $folio->condicion_termica?->value,
                    ], static fn (mixed $valor): bool => $valor !== null && $valor !== ''),
                ];
            })
            ->all();

        try {
            $plan = $this->planes->crear(
                temporada: $temporada,
                tipo: TipoPlanOperacional::RecepcionRepaletizaje,
                titulo: sprintf('Retirar resultados de REPA · %s', $repaletizaje->codigo),
                creadoPor: $usuario,
                tareas: $tareas,
                prioridad: PrioridadOperacional::Normal,
                motivo: 'Repaletizaje confirmado: liberar el área REPA y ubicar sus pallets completos.',
                referenciaTipo: self::REFERENCIA_TIPO,
                referenciaId: $repaletizaje->id,
                contexto: [
                    'planner_horizon' => 'rolling',
                    'origen_logico' => 'repaletizaje',
                    'origen_repa_sin_blockers' => true,
                    'repaletizaje_id' => $repaletizaje->id,
                    'repaletizaje_codigo' => $repaletizaje->codigo,
                    'condicion_termino' => 'todos_pallets_repa_completados',
                    'total_resultados' => $repaletizaje->resultados->count(),
                    'total_pallets_planificados' => count($tareas),
                ],
            );
            $this->prioridadBuffer->recalcular($temporada->id);

            return $plan->refresh()->load('tareas');
        } catch (UniqueConstraintViolationException) {
            $plan = $this->planExistente($repaletizaje->id)
                ?? throw new DomainException(
                    'No fue posible recuperar el objetivo REPA creado concurrentemente.',
                );
            $this->prioridadBuffer->recalcular($plan->temporada_id);

            return $plan->refresh();
        }
    }

    public function cancelarPorAnulacion(
        Repaletizaje $repaletizaje,
        User $usuario,
    ): void {
        $plan = $this->planExistente($repaletizaje->id);
        if (! $plan || $plan->estado === EstadoPlanOperacional::Cancelado) {
            return;
        }
        if ($plan->estado === EstadoPlanOperacional::Completado) {
            throw new ConflictoOperacion(
                'No se puede anular porque el retiro de REPA ya fue completado.',
            );
        }

        $tareas = $plan->tareas()->lockForUpdate()->get();
        if ($tareas->contains(fn ($tarea): bool => ! in_array($tarea->estado, [
            EstadoTareaMovimiento::Pendiente,
            EstadoTareaMovimiento::Asumida,
        ], true))) {
            throw new ConflictoOperacion(
                'No se puede anular porque el retiro de REPA ya posee ejecución operacional.',
            );
        }

        $motivo = 'Repaletizaje anulado antes de ejecutar su retiro.';
        foreach ($tareas as $tarea) {
            if (! $this->planes->cancelarPorReplanificacion($tarea, $usuario, $motivo)) {
                throw new ConflictoOperacion(
                    'No fue posible liberar la labor de retiro asociada al repaletizaje.',
                );
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

    private function requiereRetiro(RepaletizajeResultado $resultado): bool
    {
        $folio = $resultado->folio;

        return $resultado->tipo_resultado === TipoBulto::Pallet->value
            && $folio
            && $folio->activo
            && $folio->tipo_bulto === TipoBulto::Pallet
            && $folio->condicion_termica === CondicionTermicaFolio::PrefrioAprobado;
    }

    private function planExistente(string $repaletizajeId): ?PlanOperacional
    {
        return PlanOperacional::query()
            ->where('referencia_tipo', self::REFERENCIA_TIPO)
            ->where('referencia_id', $repaletizajeId)
            ->lockForUpdate()
            ->first();
    }
}
