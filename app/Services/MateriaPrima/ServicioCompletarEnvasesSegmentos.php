<?php

namespace App\Services\MateriaPrima;

use App\Enums\EstadoLoteMateriaPrima;
use App\Models\EventoLoteMateriaPrima;
use App\Models\LoteMateriaPrima;
use App\Models\SegmentoValidacionMp;
use App\Services\Temporadas\GuardiaTemporadaActiva;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioCompletarEnvasesSegmentos
{
    public function __construct(
        private readonly ServicioTemporadaActiva $temporadas,
        private readonly GuardiaTemporadaActiva $guardia,
    ) {}

    /** @return array<string, mixed> */
    public function ejecutar(bool $aplicar = false): array
    {
        // La vista previa no escribe. Al aplicar, temporada, segmentos y lotes se
        // bloquean y se vuelven a leer dentro de una sola transacción.
        return $aplicar
            ? DB::transaction(fn (): array => $this->evaluar(true), attempts: 3)
            : $this->evaluar(false);
    }

    /** @return array<string, mixed> */
    private function evaluar(bool $aplicar): array
    {
        $temporada = $this->temporadas->buscar($aplicar);
        $resumen = [
            'lotes_completados' => 0,
            'brutos_recalculados' => 0,
            'segmentos_cerrados' => 0,
            'segmentos_divididos' => 0,
            'omitidos_otras_temporadas' => 0,
            'omitidos_entregados' => 0,
            'omitidos_anulados' => 0,
            'omitidos_cantidades_incompatibles' => 0,
        ];
        if (! $temporada) {
            return ['temporada' => null, 'resumen' => $resumen, 'lotes' => []];
        }

        $resumen['omitidos_otras_temporadas'] = LoteMateriaPrima::query()
            ->where('temporada_id', '!=', $temporada->id)->count();
        $resumen['omitidos_entregados'] = LoteMateriaPrima::query()
            ->where('temporada_id', $temporada->id)
            ->whereIn('estado', [
                EstadoLoteMateriaPrima::EntregaParcialProceso->value,
                EstadoLoteMateriaPrima::EntregadoProceso->value,
            ])->count();
        $resumen['omitidos_anulados'] = LoteMateriaPrima::query()
            ->where('temporada_id', $temporada->id)
            ->where('estado', EstadoLoteMateriaPrima::Anulado->value)->count();

        $segmentos = SegmentoValidacionMp::query()
            ->whereHas('lotesMateriaPrima', fn (Builder $consulta) => $consulta->where('temporada_id', $temporada->id))
            ->orderBy('id')
            ->when($aplicar, fn (Builder $consulta) => $consulta->lockForUpdate())
            ->get();
        $filas = [];
        foreach ($segmentos as $segmento) {
            $lotes = LoteMateriaPrima::query()
                ->where('segmento_validacion_mp_id', $segmento->id)
                ->where('estado', '!=', EstadoLoteMateriaPrima::Anulado->value)
                ->orderBy('id')
                ->when($aplicar, fn (Builder $consulta) => $consulta->lockForUpdate())
                ->get();
            if ($lotes->count() > 1) {
                $resumen['segmentos_divididos']++;

                continue;
            }
            $lote = $lotes->first();
            if (! $lote || $lote->temporada_id !== $temporada->id
                || in_array($lote->estado, [
                    EstadoLoteMateriaPrima::EntregaParcialProceso,
                    EstadoLoteMateriaPrima::EntregadoProceso,
                ], true)) {
                continue;
            }

            $esperados = $segmento->envases()->where('cantidad', '>', 0)->get()->keyBy(
                fn ($envase): string => $envase->tipo_envase->value,
            );
            $actuales = $lote->envasesDetalle()->when($aplicar, fn ($consulta) => $consulta->lockForUpdate())
                ->get()->keyBy(fn ($envase): string => $envase->tipo_envase->value);
            $incompatible = $actuales->contains(fn ($envase): bool => ! $esperados->has($envase->tipo_envase->value)
                || (int) $envase->cantidad !== (int) $esperados->get($envase->tipo_envase->value)->cantidad);
            if ($incompatible) {
                $resumen['omitidos_cantidades_incompatibles']++;
                $filas[] = [
                    'numero_lote' => $lote->numero_lote, 'lote_id' => $lote->id,
                    'envases_agregados' => [], 'bruto_anterior' => (float) $lote->kilos_brutos,
                    'bruto_nuevo' => (float) $lote->kilos_brutos, 'estado' => 'revisar_cantidades',
                ];

                continue;
            }

            $tarasRomana = DB::table('detalles_envases_recepcion_romana')
                ->where('recepcion_romana_id', $lote->recepcion_romana_id)
                ->get()->keyBy('tipo_envase');
            $agregados = [];
            $taraTotal = 0.0;
            $tarasCompletas = true;
            foreach ($esperados as $tipo => $envase) {
                $existente = $actuales->get($tipo);
                $tara = $existente?->tara_unitaria
                    ?? ($existente ? null : $tarasRomana->get($tipo)?->tara_unitaria_salida);
                if (! $existente) {
                    $agregados[] = ['tipo_envase' => $tipo, 'cantidad' => (int) $envase->cantidad,
                        'tara_unitaria' => $tara !== null ? (float) $tara : null];
                }
                if ($tara === null) {
                    $tarasCompletas = false;
                } else {
                    $taraTotal += (int) $envase->cantidad * (float) $tara;
                }
            }
            $brutoAnterior = (float) $lote->kilos_brutos;
            $brutoNuevo = $tarasCompletas
                ? round((float) $lote->kilos_netos_calculados + $taraTotal, 3)
                : $brutoAnterior;
            $recalcular = $tarasCompletas && abs($brutoNuevo - $brutoAnterior) > 0.0005;
            $cerrar = $segmento->estado !== 'lotizado' && $esperados->isNotEmpty();
            $filas[] = [
                'numero_lote' => $lote->numero_lote, 'lote_id' => $lote->id,
                'envases_agregados' => $agregados, 'bruto_anterior' => $brutoAnterior,
                'bruto_nuevo' => $brutoNuevo,
                'estado' => ! $tarasCompletas ? 'sin_tara' : ($agregados || $recalcular || $cerrar ? 'completar' : 'sin_cambios'),
            ];
            $resumen['lotes_completados'] += $agregados ? 1 : 0;
            $resumen['brutos_recalculados'] += (int) $recalcular;
            $resumen['segmentos_cerrados'] += (int) $cerrar;
            if (! $aplicar || (! $agregados && ! $recalcular && ! $cerrar)) {
                continue;
            }

            $this->guardia->asegurar($lote);
            foreach ($agregados as $envase) {
                $lote->envasesDetalle()->create($envase);
            }
            if ($recalcular) {
                $lote->update(['kilos_brutos' => $brutoNuevo, 'version' => $lote->version + 1]);
            } elseif ($agregados) {
                $lote->increment('version');
            }
            if ($cerrar) {
                $segmento->update(['estado' => 'lotizado']);
            }
            EventoLoteMateriaPrima::create([
                'lote_materia_prima_id' => $lote->id,
                'operacion_id' => (string) Str::uuid(),
                'tipo' => 'envases_segmento_completados',
                'estado_anterior' => $lote->estado->value,
                'estado_nuevo' => $lote->estado->value,
                'user_id' => null,
                'ocurrido_at' => now(),
                'datos' => [
                    'origen' => 'materia-prima:completar-envases-segmentos',
                    'bruto_anterior' => $brutoAnterior, 'bruto_nuevo' => $brutoNuevo,
                    'envases_agregados' => $agregados, 'segmento_cerrado' => $cerrar,
                ],
            ]);
        }

        return ['temporada' => $temporada->codigo, 'resumen' => $resumen, 'lotes' => $filas];
    }
}
