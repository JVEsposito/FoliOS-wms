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
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

class ServicioGeneracionRecepcionRepaletizaje
{
    private const REFERENCIA_TIPO = 'repaletizaje';

    /** Marca en un retiro de REPA que otra REPA volvió a armar el pallet antes de retirarlo (resuelve su objetivo). */
    public const SUPERADA_POR_REPALETIZAJE = 'superada_por_repaletizaje';

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
            || ! in_array(config('planificador.compute'), ['tablet', 'server'], true)) {
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

        $superadas = $this->relevarRetirosAnteriores($repaletizaje, $usuario);

        $resultados = $repaletizaje->resultados
            ->filter(fn (RepaletizajeResultado $resultado): bool => $this->requiereRetiro($resultado))
            ->values();

        if ($resultados->isEmpty()) {
            $this->marcarSuperadas($superadas, $repaletizaje, null);

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
            $this->marcarSuperadas($superadas, $repaletizaje, $plan);
            $this->prioridadBuffer->recalcular($temporada->id);

            return $plan->refresh()->load('tareas');
        } catch (UniqueConstraintViolationException) {
            $plan = $this->planExistente($repaletizaje->id)
                ?? throw new DomainException(
                    'No fue posible recuperar el objetivo REPA creado concurrentemente.',
                );
            $this->marcarSuperadas($superadas, $repaletizaje, $plan);
            $this->prioridadBuffer->recalcular($plan->temporada_id);

            return $plan->refresh();
        }
    }

    /**
     * Un pallet que vuelve a repaletizarse antes de salir de REPA deja obsoleto el retiro que le pidió una REPA
     * anterior. Se cancela aquí, en la misma transacción, para que esta REPA pueda pedir su propio retiro.
     * Un retiro que ya está moviendo el pallet no se toca: la repa se rechaza.
     *
     * @return Collection<int, TareaMovimiento>
     */
    private function relevarRetirosAnteriores(Repaletizaje $repaletizaje, User $usuario): Collection
    {
        $folioIds = $repaletizaje->detalles()->pluck('folio_origen_id')
            ->merge($repaletizaje->resultados->pluck('folio_id'))
            ->filter()
            ->unique()
            ->values();
        if ($folioIds->isEmpty()) {
            return collect();
        }

        $tareas = TareaMovimiento::query()
            ->whereIn('folio_id', $folioIds)
            ->whereIn('estado', [
                EstadoTareaMovimiento::Bloqueada->value,
                EstadoTareaMovimiento::Pendiente->value,
                EstadoTareaMovimiento::Asumida->value,
                EstadoTareaMovimiento::EnProceso->value,
            ])
            ->whereHas('planOperacional', fn ($plan) => $plan
                ->where('tipo', TipoPlanOperacional::RecepcionRepaletizaje->value)
                ->where('referencia_tipo', self::REFERENCIA_TIPO)
                ->where('referencia_id', '!=', $repaletizaje->id))
            ->with(['folio:id,numero_folio', 'planOperacional:id,contexto'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $enMovimiento = $tareas->first(
            fn (TareaMovimiento $tarea): bool => $tarea->estado === EstadoTareaMovimiento::EnProceso,
        );
        if ($enMovimiento) {
            throw new ConflictoOperacion(sprintf(
                'El pallet %s ya está saliendo de REPA por el retiro de %s; termina esa labor antes de repaletizarlo.',
                $enMovimiento->folio->numero_folio,
                $this->codigoAnterior($enMovimiento),
            ));
        }

        return $tareas->map(function (TareaMovimiento $tarea) use ($repaletizaje, $usuario): TareaMovimiento {
            $motivo = sprintf(
                '%s volvió a repaletizarse en %s: su retiro de %s ya no corresponde.',
                $tarea->folio->numero_folio,
                $repaletizaje->codigo,
                $this->codigoAnterior($tarea),
            );
            $cancelada = $this->planes->cancelarPorReplanificacion($tarea, $usuario, $motivo);
            if ($cancelada?->estado !== EstadoTareaMovimiento::Cancelada) {
                throw new ConflictoOperacion(sprintf(
                    'No fue posible liberar el retiro de %s pendiente para %s.',
                    $this->codigoAnterior($tarea),
                    $tarea->folio->numero_folio,
                ));
            }

            return $cancelada;
        });
    }

    /**
     * Deja constancia de qué REPA superó cada retiro y, si el pallet vuelve a requerir retiro, de la labor que lo
     * reemplaza. Con la marca, el objetivo de la REPA anterior puede cerrarse.
     *
     * @param  Collection<int, TareaMovimiento>  $superadas
     */
    private function marcarSuperadas(
        Collection $superadas,
        Repaletizaje $repaletizaje,
        ?PlanOperacional $plan,
    ): void {
        if ($superadas->isEmpty()) {
            return;
        }
        $reemplazos = $plan
            ? $plan->tareas()->get(['id', 'folio_id'])->keyBy('folio_id')
            : collect();

        foreach ($superadas as $tarea) {
            $tarea->refresh();
            $tarea->update([
                'reemplazada_por_tarea_id' => $reemplazos->get($tarea->folio_id)?->id,
                'contexto' => [
                    ...($tarea->contexto ?? []),
                    self::SUPERADA_POR_REPALETIZAJE => $repaletizaje->codigo,
                ],
                'version' => $tarea->version + 1,
            ]);
        }
    }

    private function codigoAnterior(TareaMovimiento $tarea): string
    {
        return $tarea->contexto['repaletizaje_codigo']
            ?? $tarea->planOperacional?->contexto['repaletizaje_codigo']
            ?? 'una REPA anterior';
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
