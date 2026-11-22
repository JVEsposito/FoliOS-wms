<?php

namespace App\Services\Asistente;

use App\Enums\EstadoRecepcionRomana;
use App\Models\ConfiguracionAsistente;
use App\Models\DetalleEnvaseRecepcionRomana;
use App\Models\LoteMateriaPrima;
use App\Models\RangoTaraAsistente;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Revisión de tara y destare con los criterios de la planta:
 *
 * 1. neto por tote dentro del rango configurado (8,2 a 9,5 kg al inicio);
 * 2. tara de bins y totes igual a la oficial congelada en la recepción;
 * 3. tara de esponjas dentro de lo habitual para ese tipo en la temporada;
 * 4. neto por tote de cada lote dentro de lo habitual para su CSG.
 *
 * «Lo habitual» es la media ± k desviaciones estándar, con k configurable. Con menos de
 * MUESTRAS_MINIMAS no se marca nada: la historia no alcanza para decir que algo es raro.
 */
class RevisionTaras
{
    public const MUESTRAS_MINIMAS = 10;

    private const TOLERANCIA_TARA_KG = 0.001;

    /**
     * @return list<array{regla: string, recepcion: string, lote: ?string, detalle: string,
     *     valor: float, referencia: string, sentido: string}>
     */
    public function revisar(Temporada $temporada, CarbonImmutable $desde, CarbonImmutable $hasta, ?string $clienteId = null): array
    {
        $k = ConfiguracionAsistente::actual()->margen_desviaciones;
        $recepciones = RecepcionRomana::query()
            ->where('temporada_id', $temporada->id)
            ->whereNull('eliminada_at')
            ->where('estado', EstadoRecepcionRomana::Cerrado->value)
            ->where('ingreso_at', '>=', $desde)->where('ingreso_at', '<', $hasta)
            ->when($clienteId, fn ($q, $id) => $q->where('cliente_id', $id))
            ->with(['detallesEnvases', 'lotesMateriaPrima' => fn ($q) => $q->whereNull('anulado_at')->with('especie:id,nombre')])
            ->get();

        return [
            ...$this->netoPorTote($recepciones),
            ...$this->taraEnvase($recepciones),
            ...$this->esponjas($recepciones, $temporada, $k),
            ...$this->historicoCsg($recepciones, $temporada, $k),
        ];
    }

    /** @param  Collection<int, RecepcionRomana>  $recepciones */
    private function netoPorTote(Collection $recepciones): array
    {
        $hallazgos = [];
        foreach ($recepciones as $recepcion) {
            if ($recepcion->tipo_envase_calculo_neto !== 'totes' || $recepcion->peso_neto_por_envase === null
                || (int) $recepcion->cantidad_envase_calculo_neto <= 0) {
                continue;
            }
            $especies = $recepcion->lotesMateriaPrima->pluck('especie.nombre')->filter()->unique();
            $rango = RangoTaraAsistente::vigentePara('totes', $especies->count() === 1 ? $especies->first() : null);
            $valor = round((float) $recepcion->peso_neto_por_envase, 3);
            if ($rango === null || $rango->contiene($valor)) {
                continue;
            }
            $hallazgos[] = $this->hallazgo(
                'neto_por_tote', $recepcion, null,
                sprintf('Neto por tote %s kg, fuera del rango %s a %s kg.', $this->kg($valor), $this->kg($rango->minimo_kg), $this->kg($rango->maximo_kg)),
                $valor,
                $this->kg($rango->minimo_kg).'–'.$this->kg($rango->maximo_kg).' kg',
                $valor < $rango->minimo_kg ? 'bajo' : 'sobre',
            );
        }

        return $hallazgos;
    }

    /** @param  Collection<int, RecepcionRomana>  $recepciones */
    private function taraEnvase(Collection $recepciones): array
    {
        $hallazgos = [];
        foreach ($recepciones as $recepcion) {
            $oficiales = $recepcion->taras_romana_snapshot ?? [];
            foreach ($recepcion->detallesEnvases as $detalle) {
                $tipo = $detalle->tipo_envase->value;
                if (! in_array($tipo, ['bins', 'totes'], true) || ! array_key_exists($tipo, $oficiales) || $detalle->tara_unitaria_salida === null) {
                    continue;
                }
                $oficial = (float) $oficiales[$tipo];
                $aplicada = (float) $detalle->tara_unitaria_salida;
                if (abs($aplicada - $oficial) <= self::TOLERANCIA_TARA_KG) {
                    continue;
                }
                $hallazgos[] = $this->hallazgo(
                    'tara_envase', $recepcion, null,
                    sprintf('Tara de %s %s kg; la oficial de la recepción es %s kg.', $detalle->tipo_envase->etiqueta(), $this->kg($aplicada), $this->kg($oficial)),
                    $aplicada, $this->kg($oficial).' kg', 'distinta',
                );
            }
        }

        return $hallazgos;
    }

    /** @param  Collection<int, RecepcionRomana>  $recepciones */
    private function esponjas(Collection $recepciones, Temporada $temporada, float $k): array
    {
        $tipos = ['esponjas', 'esponja_tote'];
        $muestras = DetalleEnvaseRecepcionRomana::query()
            ->join('recepciones_romana', 'recepciones_romana.id', '=', 'detalles_envases_recepcion_romana.recepcion_romana_id')
            ->where('recepciones_romana.temporada_id', $temporada->id)
            ->whereNull('recepciones_romana.eliminada_at')
            ->where('recepciones_romana.estado', EstadoRecepcionRomana::Cerrado->value)
            ->whereIn('detalles_envases_recepcion_romana.tipo_envase', $tipos)
            ->whereNotNull('detalles_envases_recepcion_romana.tara_unitaria_salida')
            ->get(['detalles_envases_recepcion_romana.id', 'detalles_envases_recepcion_romana.tipo_envase', 'detalles_envases_recepcion_romana.tara_unitaria_salida'])
            ->groupBy(fn ($fila): string => $fila->tipo_envase->value);

        $hallazgos = [];
        foreach ($recepciones as $recepcion) {
            foreach ($recepcion->detallesEnvases as $detalle) {
                $tipo = $detalle->tipo_envase->value;
                if (! in_array($tipo, $tipos, true) || $detalle->tara_unitaria_salida === null) {
                    continue;
                }
                $otras = ($muestras[$tipo] ?? collect())->reject(fn ($fila): bool => $fila->id === $detalle->id)
                    ->map(fn ($fila): float => (float) $fila->tara_unitaria_salida)->values()->all();
                $valor = (float) $detalle->tara_unitaria_salida;
                $fuera = $this->fueraDeLoHabitual($valor, $otras, $k);
                if ($fuera === null) {
                    continue;
                }
                [$media, $sentido] = $fuera;
                $hallazgos[] = $this->hallazgo(
                    'esponja', $recepcion, null,
                    sprintf('Tara de %s %s kg; lo habitual en la temporada es %s kg.', $detalle->tipo_envase->etiqueta(), $this->kg($valor), $this->kg($media)),
                    $valor, $this->kg($media).' kg', $sentido,
                );
            }
        }

        return $hallazgos;
    }

    /** @param  Collection<int, RecepcionRomana>  $recepciones */
    private function historicoCsg(Collection $recepciones, Temporada $temporada, float $k): array
    {
        $lotes = $recepciones->flatMap(fn (RecepcionRomana $r) => $r->lotesMateriaPrima->map(fn ($lote) => [$r, $lote]))
            ->filter(fn (array $par): bool => $this->netoPorToteLote($par[1]) !== null && $par[1]->csg_validacion_id !== null);
        if ($lotes->isEmpty()) {
            return [];
        }

        $anterior = Temporada::query()->where('id', '!=', $temporada->id)
            ->where('created_at', '<', $temporada->created_at)->latest('created_at')->value('id');
        $codigos = $lotes->map(fn (array $par): ?string => $par[1]->csg_snapshot)->filter()->unique()->values();
        $historia = LoteMateriaPrima::query()
            ->whereIn('temporada_id', array_filter([$temporada->id, $anterior]))
            ->whereNull('anulado_at')
            ->where(fn ($q) => $q->whereIn('csg_validacion_id', $lotes->map(fn (array $par) => $par[1]->csg_validacion_id)->unique()->values())
                ->orWhereIn('csg_snapshot', $codigos))
            ->get(['id', 'temporada_id', 'csg_validacion_id', 'csg_snapshot', 'envase_primario', 'cantidad_envases_primarios', 'kilos_netos_confirmados']);

        $hallazgos = [];
        foreach ($lotes as [$recepcion, $lote]) {
            $valor = $this->netoPorToteLote($lote);
            // El CSG de la temporada anterior es otro registro: se reconoce por su código.
            $otras = $historia->filter(fn ($h): bool => $h->id !== $lote->id
                && ($h->csg_validacion_id === $lote->csg_validacion_id || ($h->temporada_id === $anterior && $h->csg_snapshot === $lote->csg_snapshot)))
                ->map(fn ($h): ?float => $this->netoPorToteLote($h))->filter(fn ($v): bool => $v !== null)->values()->all();
            $fuera = $this->fueraDeLoHabitual($valor, $otras, $k);
            if ($fuera === null) {
                continue;
            }
            [$media, $sentido] = $fuera;
            $hallazgos[] = $this->hallazgo(
                'historico_csg', $recepcion, (string) $lote->numero_lote,
                sprintf('Lote %s del CSG %s: %s kg por tote; su CSG promedia %s kg.', $lote->numero_lote, $lote->csg_snapshot, $this->kg($valor), $this->kg($media)),
                $valor, $this->kg($media).' kg', $sentido,
            );
        }

        return $hallazgos;
    }

    private function netoPorToteLote(LoteMateriaPrima $lote): ?float
    {
        $envase = mb_strtolower((string) $lote->envase_primario);
        $cantidad = (int) $lote->cantidad_envases_primarios;
        if (! str_contains($envase, 'tote') || $cantidad <= 0 || $lote->kilos_netos_confirmados === null) {
            return null;
        }

        return round((float) $lote->kilos_netos_confirmados / $cantidad, 3);
    }

    /**
     * @param  list<float>  $muestras
     * @return array{0: float, 1: string}|null media y sentido cuando está fuera; null si no
     */
    private function fueraDeLoHabitual(float $valor, array $muestras, float $k): ?array
    {
        if (count($muestras) < self::MUESTRAS_MINIMAS) {
            return null;
        }
        $media = array_sum($muestras) / count($muestras);
        $varianza = array_sum(array_map(fn (float $m): float => ($m - $media) ** 2, $muestras)) / count($muestras);
        $desviacion = sqrt($varianza);
        if (abs($valor - $media) <= $k * $desviacion + self::TOLERANCIA_TARA_KG) {
            return null;
        }

        return [round($media, 3), $valor < $media ? 'bajo' : 'sobre'];
    }

    private function hallazgo(string $regla, RecepcionRomana $recepcion, ?string $lote, string $detalle, float $valor, string $referencia, string $sentido): array
    {
        return [
            'regla' => $regla,
            'recepcion' => $recepcion->numero_recepcion,
            'lote' => $lote,
            'detalle' => $detalle,
            'valor' => $valor,
            'referencia' => $referencia,
            'sentido' => $sentido,
        ];
    }

    private function kg(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 3, ',', '.'), '0'), ',');
    }
}
